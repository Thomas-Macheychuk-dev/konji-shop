<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Vaya\VayaProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports Vaya variants multiple category paths medical VAT attributes and safe descriptions', function (): void {
    $product = app(VayaProductImporter::class)
        ->import(vayaImporterProductPayload(), ProductStatus::DRAFT, false)['product'];

    expect($product->external_source)->toBe('vaya')
        ->and($product->external_id)->toBe('895')
        ->and($product->external_parent_sku)->toBe('VAYA-1314')
        ->and($product->description)
        ->toContain('Opis produktu')
        ->toContain('Dane produktu')
        ->toContain('Bezpieczeństwo i zgodność')
        ->toContain('Posiada oznaczenie CE')
        ->not->toContain('Źródło')
        ->not->toContain('Dane produktu zaimportowane z Vaya')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $categoryNames = $product->categories()->pluck('categories.name')->all();
    $primaryCategory = $product->categories()->wherePivot('is_primary', true)->first();

    expect($categoryNames)->toContain(
        'Wkładki ortopedyczne',
        'Wkładki na modzele',
        'Wkładki na bunionette',
    )->and($product->categories()->count())->toBe(3)
        ->and($primaryCategory?->name)->toBe('Wkładki na modzele');

    $variants = $product->variants()->orderBy('external_variant_id')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('external_variant_id')->all())->toBe([
            'vaya-895-943',
            'vaya-895-944',
        ])
        ->and($variants->pluck('sku')->all())->toBe([
            'VAYA-1314-S-M',
            'VAYA-1314-L-XL',
        ])
        ->and($variants->pluck('price_gross_amount')->all())->toBe([5190, 5190])
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8))->toBeTrue()
        ->and($variants->first()?->is_default)->toBeTrue()
        ->and($variants->last()?->is_default)->toBeFalse();

    $attributes = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($attributes)->toContain(
        'Producent=Vaya Medical',
        'Wyrób medyczny=Tak',
        'Materiał=medyczny polimer żelowy TPE',
    );
});

it('imports integer Vaya prices as major units uses 23 percent VAT and ignores placeholder brands', function (): void {
    $payload = vayaImporterProductPayload([
        'external_product_id' => '577',
        'source_url' => 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577',
        'canonical_url' => 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577',
        'slug' => 'termometr-elektroniczny',
        'name' => 'Termometr elektroniczny',
        'brand' => ['name' => '-', 'slug' => null],
        'sku' => 'HK-902',
        'ean' => '6932053201728',
        'price_gross_amount' => 12,
        'is_medical_device' => false,
        'source_category_paths' => [
            ['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry'],
        ],
        'source_category_path' => ['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry'],
        'variant_candidates' => [],
        'attributes' => [
            [
                'code' => 'wyswietlacz',
                'label' => 'Wyświetlacz',
                'value' => 'LCD',
                'slug' => 'lcd',
            ],
        ],
    ]);

    $product = app(VayaProductImporter::class)
        ->import($payload, ProductStatus::ACTIVE, false)['product'];
    $variant = $product->variants()->firstOrFail();

    expect($variant->price_gross_amount)->toBe(1200)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variant->sku)->toBe('HK-902')
        ->and($variant->external_variant_id)->toBe('vaya-577-default')
        ->and($product->description)->toContain('6932053201728')
        ->and($product->description)->not->toContain('<td>-</td>');

    $attributePairs = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($attributePairs)->toContain('Wyświetlacz=LCD')
        ->not->toContain('Producent=-')
        ->not->toContain('Wyrób medyczny=Tak');
});

it('retries rate-limited Vaya images with browser headers and a product referer', function (): void {
    Storage::fake('public');

    $sourceUrl = 'https://www.vaya.com.pl/pl/p/Scholl-Kliny-miedzypalcowe/286';
    $imageUrl = 'https://www.vaya.com.pl/userdata/public/gfx/test-scholl.jpg';
    $downloadUrl = 'https://vaya.com.pl/userdata/public/gfx/test-scholl.jpg';
    $imageContents = vayaImporterTestImageContents();

    Http::fake([
        $downloadUrl => Http::sequence()
            ->push('<html><title>Maintenance</title></html>', 429, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ])
            ->push($imageContents, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        $imageUrl => Http::response('<html><title>Maintenance</title></html>', 429),
    ]);

    $payload = vayaImporterProductPayload([
        'external_product_id' => '286',
        'source_url' => $sourceUrl,
        'canonical_url' => $sourceUrl,
        'slug' => 'scholl-kliny-miedzypalcowe',
        'name' => 'Scholl Kliny międzypalcowe',
        'sku' => 'RB8084814',
        'variant_candidates' => [],
        'images' => [
            [
                'url' => $imageUrl,
                'alt' => 'Scholl Kliny międzypalcowe',
                'title' => null,
            ],
        ],
    ]);

    $result = app(VayaProductImporter::class)->import(
        scraped: $payload,
        status: ProductStatus::DRAFT,
        importImages: true,
        imageLimit: 50,
        imageTimeoutSeconds: 5,
        imageAttempts: 2,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );

    $image = $result['product']->images()->firstOrFail();

    expect($result['warnings'])->toBe([])
        ->and($result['product']->images()->count())->toBe(1)
        ->and($image->source_url)->toBe($imageUrl);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) use ($downloadUrl, $sourceUrl): bool {
        return $request->url() === $downloadUrl
            && $request->hasHeader('Referer', str_replace('https://www.', 'https://', $sourceUrl))
            && $request->hasHeader(
                'User-Agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                    .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            )
            && $request->hasHeader('Sec-Fetch-Dest', 'image');
    });
});

it('keeps Vaya imports idempotent and archives variants removed from later source data', function (): void {
    $importer = app(VayaProductImporter::class);
    $firstProduct = $importer->import(vayaImporterProductPayload(), ProductStatus::DRAFT, false)['product'];
    $firstSkus = $firstProduct->variants()->orderBy('external_variant_id')->pluck('sku')->all();

    $updatedPayload = vayaImporterProductPayload();
    $updatedPayload['variant_candidates'] = [$updatedPayload['variant_candidates'][0]];

    $secondProduct = $importer->import($updatedPayload, ProductStatus::DRAFT, false)['product'];
    $variants = $secondProduct->variants()->orderBy('external_variant_id')->get();

    expect($secondProduct->id)->toBe($firstProduct->id)
        ->and(Product::query()->where('external_source', 'vaya')->where('external_id', '895')->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants->first()->sku)->toBe($firstSkus[0])
        ->and($variants->first()->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variants->last()->status)->toBe(ProductVariantStatus::ARCHIVED)
        ->and($variants->last()->is_default)->toBeFalse();
});

it('runs the Vaya import command with limit offset and no image downloads', function (): void {
    $relativePath = 'scrapers/vaya/tests/import-product-data.json';
    $absolutePath = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    $second = vayaImporterProductPayload([
        'external_product_id' => '577',
        'source_url' => 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577',
        'canonical_url' => 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577',
        'slug' => 'termometr-elektroniczny',
        'name' => 'Termometr elektroniczny',
        'sku' => 'HK-902',
        'price_gross_amount' => 11.4,
        'source_category_paths' => [
            ['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry'],
        ],
        'source_category_path' => ['Produkty Medyczne', 'Akcesoria medyczne', 'Termometry'],
        'variant_candidates' => [],
    ]);

    file_put_contents($absolutePath, json_encode([
        'source' => 'vaya',
        'products' => [
            vayaImporterProductPayload(),
            $second,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
        $exitCode = Artisan::call('vaya:import', [
            '--from' => $relativePath,
            '--offset' => '1',
            '--limit' => '1',
            '--status' => 'draft',
            '--no-images' => true,
            '--show-failures' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Available products: 2')
            ->and($output)->toContain('Offset: 1')
            ->and($output)->toContain('Selected products: 1')
            ->and($output)->toContain('Images: skipped')
            ->and($output)->toContain('Imported products: 1')
            ->and($output)->toContain('Failures: 0')
            ->and(Product::query()->where('external_source', 'vaya')->where('external_id', '895')->exists())->toBeFalse()
            ->and(Product::query()->where('external_source', 'vaya')->where('external_id', '577')->exists())->toBeTrue();
    } finally {
        @unlink($absolutePath);
    }
});

function vayaImporterProductPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'vaya',
        'source_url' => 'https://www.vaya.com.pl/pl/p/Zelowe-poduszki-na-zrogowacenia/895',
        'canonical_url' => 'https://www.vaya.com.pl/pl/p/Zelowe-poduszki-na-zrogowacenia/895',
        'external_product_id' => '895',
        'slug' => 'zelowe-poduszki-na-zrogowacenia',
        'name' => 'Żelowe poduszki na zrogowacenia 5 w 1',
        'brand' => ['name' => 'Vaya Medical', 'slug' => 'vaya-medical'],
        'sku' => 'VAYA-1314',
        'ean' => null,
        'price_gross_amount' => 51.9,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'duża ilość',
        'shipping_time' => '24 godziny',
        'is_medical_device' => true,
        'seo_title' => 'Żelowe poduszki na zrogowacenia 5 w 1',
        'seo_description' => 'Żelowe poduszki ochronne do stóp.',
        'short_description' => 'Żelowe poduszki ochronne do stóp.',
        'description_html' => '<p>Opis produktu medycznego.</p><script>alert(1)</script><img src="https://www.vaya.com.pl/test.jpg">',
        'safety_html' => '<p>Posiada oznaczenie CE.</p>',
        'source_category_paths' => [
            ['Wkładki ortopedyczne', 'Wkładki na modzele'],
            ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
        ],
        'source_category_path' => ['Wkładki ortopedyczne', 'Wkładki na modzele'],
        'attributes' => [
            [
                'code' => 'material',
                'label' => 'Materiał',
                'value' => 'medyczny polimer żelowy TPE',
                'slug' => 'medyczny-polimer-zelowy-tpe',
            ],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '943',
                'sku' => null,
                'label' => 'S/M',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'S/M'],
                ],
                'price_gross_amount' => 51.9,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => '944',
                'sku' => null,
                'label' => 'L/XL',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'L/XL'],
                ],
                'price_gross_amount' => 51.9,
                'currency' => 'PLN',
            ],
        ],
        'images' => [],
        'warnings' => [],
        'failed_urls' => [],
    ];

    return array_replace($payload, $overrides);
}

function vayaImporterTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Vaya importer test image.');
    }

    try {
        $background = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Vaya importer test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
