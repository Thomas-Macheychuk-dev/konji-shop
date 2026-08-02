<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Novicare\NovicareProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports Novicare size variants categories medical VAT and source guidance', function (): void {
    $product = app(NovicareProductImporter::class)
        ->import(novicareImporterSizeProductPayload(), ProductStatus::DRAFT, false)['product'];

    expect($product->external_source)->toBe('novicare')
        ->and($product->external_id)->toBe(hash('sha256', novicareImporterSizeProductUrl()))
        ->and($product->external_parent_sku)->toBe('6155')
        ->and($product->description)
        ->toContain('Opis produktu')
        ->toContain('Wskazania')
        ->toContain('Dostępne rozmiary')
        ->toContain('27 – 30')
        ->toContain('Kod produktu')
        ->toContain('6155')
        ->not->toContain('Źródło')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $primaryCategory = $product->categories()->wherePivot('is_primary', true)->first();
    $variants = $product->variants()->orderBy('external_variant_id')->get();

    expect($product->categories()->pluck('categories.name')->all())->toBe(['Kolano'])
        ->and($primaryCategory?->name)->toBe('Kolano')
        ->and($variants)->toHaveCount(2)
        ->and($variants->pluck('sku')->sort()->values()->all())->toBe(['6155-S', '6155-XS'])
        ->and($variants->every(fn ($variant): bool => $variant->price_gross_amount === null))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->price_net_amount === null))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->stock_status === StockStatus::IN_STOCK))->toBeTrue()
        ->and($variants->where('is_default', true))->toHaveCount(1);

    $productAttributes = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($productAttributes)->toContain(
        'Kod produktu=6155',
        'Producent=Novicare',
        'Wyrób medyczny=Tak',
    )->not->toContain('Rozmiar=XS, S');

    $variantOptions = $variants
        ->flatMap(fn ($variant) => $variant->attributeValues()->with('attribute')->get())
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($variantOptions)->toBe([
        'Rozmiar=S',
        'Rozmiar=XS',
    ]);
});

it('imports Novicare colour model candidates as selectable colour variants', function (): void {
    $product = app(NovicareProductImporter::class)
        ->import(novicareImporterColorProductPayload(), ProductStatus::DRAFT, false)['product'];
    $variants = $product->variants()->orderBy('sku')->get();

    expect($variants)->toHaveCount(5)
        ->and($variants->pluck('sku')->all())->toBe([
            'RB-101',
            'RB-102',
            'RB-103',
            'RB-104',
            'RB-105',
        ])
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8))->toBeTrue()
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($product->description)->toContain('Dostępne kolory')
        ->toContain('RB 101')
        ->toContain('żółta');

    $colourOptions = $variants
        ->flatMap(fn ($variant) => $variant->attributeValues()->with('attribute')->get())
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->sort()
        ->values()
        ->all();

    expect($colourOptions)->toBe([
        'Kolor=czarna',
        'Kolor=czerwona',
        'Kolor=niebieska',
        'Kolor=zielona',
        'Kolor=żółta',
    ]);
});

it('creates one default Novicare variant without inventing a universal size', function (): void {
    $payload = novicareImporterSizeProductPayload([
        'canonical_url' => 'https://novicare.pl/produkty/kolano/orteza-podrzepkowa-6876/',
        'source_url' => 'https://novicare.pl/produkty/kolano/orteza-podrzepkowa-6876/',
        'external_product_id' => hash('sha256', 'https://novicare.pl/produkty/kolano/orteza-podrzepkowa-6876/'),
        'slug' => 'orteza-podrzepkowa-6876',
        'name' => 'Orteza podrzepkowa 6876',
        'product_code' => '6876',
        'size_table' => null,
        'attributes' => [
            [
                'code' => 'kod-produktu',
                'label' => 'Kod produktu',
                'value' => '6876',
                'slug' => '6876',
            ],
        ],
        'variant_candidates' => [],
    ]);

    $product = app(NovicareProductImporter::class)
        ->import($payload, ProductStatus::DRAFT, false)['product'];
    $variant = $product->variants()->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($variant->sku)->toBe('6876')
        ->and($variant->external_variant_id)
        ->toBe('novicare-'.$product->external_id.'-default')
        ->and($variant->attributeValues()->count())->toBe(0)
        ->and($variant->is_default)->toBeTrue();
});

it('keeps Novicare imports idempotent and archives removed variants', function (): void {
    $importer = app(NovicareProductImporter::class);
    $first = $importer->import(novicareImporterSizeProductPayload(), ProductStatus::DRAFT, false)['product'];

    $updatedPayload = novicareImporterSizeProductPayload();
    $updatedPayload['variant_candidates'] = [$updatedPayload['variant_candidates'][0]];

    $second = $importer->import($updatedPayload, ProductStatus::DRAFT, false)['product'];
    $variants = $second->variants()->get()->keyBy('sku');

    expect($second->id)->toBe($first->id)
        ->and(Product::query()
            ->where('external_source', 'novicare')
            ->where('external_id', $first->external_id)
            ->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants['6155-XS']->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variants['6155-XS']->is_default)->toBeTrue()
        ->and($variants['6155-S']->status)->toBe(ProductVariantStatus::ARCHIVED)
        ->and($variants['6155-S']->is_default)->toBeFalse();
});

it('downloads Novicare images with browser headers and preserves source URLs', function (): void {
    Storage::fake('public');

    $sourceUrl = novicareImporterSizeProductUrl();
    $imageUrl = 'https://novicare.pl/wp-content/uploads/2024/09/test-6155.jpg';
    $imageContents = novicareImporterTestImageContents();

    Http::fake([
        $imageUrl => Http::response($imageContents, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $payload = novicareImporterSizeProductPayload([
        'images' => [
            [
                'url' => $imageUrl,
                'alt' => 'Orteza stawu kolanowego 6155',
                'title' => null,
                'type' => 'main',
            ],
        ],
    ]);

    $result = app(NovicareProductImporter::class)->import(
        scraped: $payload,
        status: ProductStatus::DRAFT,
        importImages: true,
        imageLimit: 10,
        imageTimeoutSeconds: 5,
        imageAttempts: 1,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );

    $image = $result['product']->images()->firstOrFail();

    expect($result['warnings'])->toBe([])
        ->and($image->source_url)->toBe($imageUrl)
        ->and($image->is_main)->toBeTrue();

    Http::assertSent(function (Request $request) use ($imageUrl, $sourceUrl): bool {
        return $request->url() === $imageUrl
            && $request->hasHeader('Referer', $sourceUrl)
            && $request->hasHeader('Sec-Fetch-Dest', 'image');
    });
});

it('runs the Novicare import command with dry-run limit offset and no images', function (): void {
    $relativePath = 'scrapers/novicare/tests/import-product-data.json';
    $absolutePath = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    file_put_contents($absolutePath, json_encode([
        'source' => 'novicare',
        'products' => [
            novicareImporterSizeProductPayload(),
            novicareImporterColorProductPayload(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
        $dryRunCode = Artisan::call('novicare:import', [
            '--from' => $relativePath,
            '--dry-run' => true,
            '--offset' => '1',
            '--limit' => '1',
        ]);
        $dryRunOutput = Artisan::output();

        expect($dryRunCode)->toBe(0)
            ->and($dryRunOutput)->toContain('Available products: 2')
            ->and($dryRunOutput)->toContain('Offset: 1')
            ->and($dryRunOutput)->toContain('Selected products: 1')
            ->and($dryRunOutput)->toContain('Variants to create/update: 5')
            ->and($dryRunOutput)->toContain('No database writes were made')
            ->and(Product::query()->where('external_source', 'novicare')->count())->toBe(0);

        $importCode = Artisan::call('novicare:import', [
            '--from' => $relativePath,
            '--offset' => '1',
            '--limit' => '1',
            '--status' => 'draft',
            '--no-images' => true,
            '--show-failures' => true,
        ]);
        $importOutput = Artisan::output();

        expect($importCode)->toBe(0)
            ->and($importOutput)->toContain('Images: skipped')
            ->and($importOutput)->toContain('Imported products: 1')
            ->and($importOutput)->toContain('Failures: 0')
            ->and(Product::query()->where('external_source', 'novicare')->count())->toBe(1)
            ->and(Product::query()
                ->where('external_source', 'novicare')
                ->where('external_id', hash('sha256', 'https://novicare.pl/produkty/akcesoria/tasmy-oporowe-do-cwiczen-dr-rb100/'))
                ->exists())->toBeTrue();
    } finally {
        @unlink($absolutePath);
    }
});

function novicareImporterSizeProductUrl(): string
{
    return 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';
}

function novicareImporterSizeProductPayload(array $overrides = []): array
{
    $url = novicareImporterSizeProductUrl();

    $payload = [
        'source' => 'novicare',
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => hash('sha256', $url),
        'slug' => 'orteza-stawu-kolanowego-6155',
        'name' => 'Orteza stawu kolanowego 6155',
        'product_code' => '6155',
        'brand' => null,
        'category' => 'Kolano',
        'categories' => ['Kolano'],
        'category_paths' => [['Kolano']],
        'seo_title' => 'Orteza stawu kolanowego 6155',
        'seo_description' => 'Orteza stawu kolanowego z szynami stabilizującymi.',
        'short_description' => 'Orteza stawu kolanowego z szynami stabilizującymi.',
        'description' => "wysokiej jakości neopren utrzymuje odpowiednią temperaturę,\nwyposażona jest w dwuzawiasowe szyny.",
        'description_html' => '<ul><li>wysokiej jakości neopren utrzymuje odpowiednią temperaturę,</li><li>wyposażona jest w dwuzawiasowe szyny.</li></ul><script>alert(1)</script><img src="https://novicare.pl/test.jpg">',
        'description_items' => [
            'wysokiej jakości neopren utrzymuje odpowiednią temperaturę,',
            'wyposażona jest w dwuzawiasowe szyny.',
        ],
        'indications' => [
            'niestabilności kolana,',
            'schorzeniach łękotki.',
        ],
        'indications_html' => '<ul><li>niestabilności kolana,</li><li>schorzeniach łękotki.</li></ul>',
        'size_table' => [
            'header_label' => 'Rozmiar',
            'sizes' => ['XS', 'S'],
            'rows' => [
                [
                    'label' => 'cm',
                    'values' => [
                        'XS' => '27 – 30',
                        'S' => '30 – 33',
                    ],
                ],
            ],
        ],
        'color_table' => null,
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'attributes' => [
            [
                'code' => 'kod-produktu',
                'label' => 'Kod produktu',
                'value' => '6155',
                'slug' => '6155',
            ],
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'XS, S',
                'slug' => 'xs-s',
            ],
        ],
        'variant_candidates' => [
            novicareImporterSizeVariant($url, 'XS', '27 – 30'),
            novicareImporterSizeVariant($url, 'S', '30 – 33'),
        ],
        'images' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ];

    return array_replace($payload, $overrides);
}

function novicareImporterSizeVariant(string $url, string $size, string $measurement): array
{
    return [
        'external_variant_id' => hash('sha256', $url.'|size|'.mb_strtolower($size)),
        'sku' => null,
        'product_code' => '6155',
        'size' => $size,
        'color' => null,
        'model_code' => null,
        'name' => $size,
        'option_values' => [
            ['attribute' => 'Rozmiar', 'value' => $size],
        ],
        'measurements' => ['cm' => $measurement],
        'measurement_label' => 'cm',
        'measurement' => $measurement,
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
    ];
}

function novicareImporterColorProductPayload(): array
{
    $url = 'https://novicare.pl/produkty/akcesoria/tasmy-oporowe-do-cwiczen-dr-rb100/';
    $options = [
        ['RB 101', 'żółta'],
        ['RB 102', 'czerwona'],
        ['RB 103', 'zielona'],
        ['RB 104', 'niebieska'],
        ['RB 105', 'czarna'],
    ];

    return [
        'source' => 'novicare',
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => hash('sha256', $url),
        'slug' => 'tasmy-oporowe-do-cwiczen-dr-rb100',
        'name' => 'Taśmy oporowe do ćwiczeń DR-RB100',
        'product_code' => 'DR-RB100',
        'category' => 'Akcesoria',
        'categories' => ['Akcesoria'],
        'category_paths' => [['Akcesoria']],
        'seo_title' => 'Taśmy oporowe do ćwiczeń DR-RB100',
        'seo_description' => 'Taśmy oporowe do ćwiczeń w pięciu kolorach.',
        'short_description' => 'Taśmy oporowe do ćwiczeń w pięciu kolorach.',
        'description' => 'Taśmy o zróżnicowanym oporze.',
        'description_html' => '<ul><li>Taśmy o zróżnicowanym oporze.</li></ul>',
        'description_items' => ['Taśmy o zróżnicowanym oporze.'],
        'indications' => ['ćwiczenia rehabilitacyjne.'],
        'indications_html' => '<ul><li>ćwiczenia rehabilitacyjne.</li></ul>',
        'size_table' => null,
        'color_table' => [
            'header_label' => 'Model',
            'models' => array_column($options, 0),
            'options' => array_map(
                static fn (array $option): array => [
                    'model_code' => $option[0],
                    'color' => $option[1],
                ],
                $options,
            ),
            'rows' => [
                [
                    'label' => 'kolor',
                    'values' => array_combine(array_column($options, 0), array_column($options, 1)),
                ],
            ],
        ],
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'attributes' => [
            [
                'code' => 'kod-produktu',
                'label' => 'Kod produktu',
                'value' => 'DR-RB100',
                'slug' => 'dr-rb100',
            ],
            [
                'code' => 'model',
                'label' => 'Model',
                'value' => implode(', ', array_column($options, 0)),
                'slug' => 'rb-101-rb-102-rb-103-rb-104-rb-105',
            ],
            [
                'code' => 'kolor',
                'label' => 'Kolor',
                'value' => implode(', ', array_column($options, 1)),
                'slug' => 'zolta-czerwona-zielona-niebieska-czarna',
            ],
        ],
        'variant_candidates' => array_map(
            static fn (array $option): array => [
                'external_variant_id' => hash(
                    'sha256',
                    $url.'|model|'.mb_strtolower($option[0]).'|color|'.mb_strtolower($option[1]),
                ),
                'sku' => null,
                'product_code' => $option[0],
                'size' => null,
                'color' => $option[1],
                'model_code' => $option[0],
                'name' => $option[0].' – '.$option[1],
                'option_values' => [
                    ['attribute' => 'Kolor', 'value' => $option[1]],
                ],
                'measurements' => [],
                'price_gross_amount' => null,
                'currency' => 'PLN',
                'availability' => 'unknown',
            ],
            $options,
        ),
        'images' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ];
}

function novicareImporterTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Novicare importer test image.');
    }

    try {
        $background = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Novicare importer test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
