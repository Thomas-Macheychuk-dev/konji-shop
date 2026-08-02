<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Microlife\MicrolifeProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports a Microlife consumer product with category branch attributes and a default variant', function (): void {
    $product = app(MicrolifeProductImporter::class)
        ->import(microlifeConsumerPayload(), ProductStatus::DRAFT, false, 50, false)['product'];

    expect($product->external_source)->toBe('microlife')
        ->and($product->external_id)->toBe(hash('sha256', microlifeConsumerUrl()))
        ->and($product->external_parent_sku)->toBe('BP-B1-STANDARD')
        ->and($product->description)
        ->toContain('Opis produktu')
        ->toContain('Najważniejsze cechy')
        ->toContain('Specyfikacja')
        ->toContain('Dokumenty i materiały do pobrania')
        ->toContain('Typ katalogu')
        ->toContain('Konsumencki')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $categoryNames = $product->categories()->pluck('categories.name')->all();
    $variant = $product->variants()->firstOrFail();

    expect($categoryNames)->toContain(
        'Microlife',
        'Produkty',
        'Ciśnienie krwi',
        'Ciśnieniomierze automatyczne',
    )
        ->and($product->categories()->wherePivot('is_primary', true)->first()?->name)
        ->toBe('Ciśnieniomierze automatyczne')
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->sku)->toBe('BP-B1-STANDARD')
        ->and($variant->external_variant_id)->toBe('microlife-'.$product->external_id.'-default')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull();

    $attributes = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($attributes)->toContain(
        'Producent=Microlife',
        'Typ katalogu=Konsumencki',
        'Kod produktu=BP B1 Standard',
        'Wyrób medyczny=Tak',
        'Pamięć=30 wyników',
    );
});

it('imports explicit Microlife professional cuff sizes as variants', function (): void {
    $product = app(MicrolifeProductImporter::class)
        ->import(microlifeProfessionalCuffPayload(), ProductStatus::DRAFT, false, 50, false)['product'];
    $variants = $product->variants()->orderBy('sku')->get();

    expect($product->categories()->pluck('categories.name')->all())->toContain(
        'Microlife',
        'Produkty profesjonalne',
        'Mankiety i wyposażenie',
    )
        ->and($variants)->toHaveCount(2)
        ->and($variants->pluck('sku')->all())->toBe([
            'WATCHBP-OFFICE-ABI-CENTRAL-M',
            'WATCHBP-OFFICE-ABI-CENTRAL-S',
        ])
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8))->toBeTrue();

    $options = $variants
        ->flatMap(fn ($variant) => $variant->attributeValues()->with('attribute')->get())
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->sort()
        ->values()
        ->all();

    expect($options)->toBe([
        'Rozmiar=M',
        'Rozmiar=S',
    ]);
});

it('keeps Microlife imports idempotent and archives removed variants', function (): void {
    $importer = app(MicrolifeProductImporter::class);
    $first = $importer->import(
        microlifeProfessionalCuffPayload(),
        ProductStatus::DRAFT,
        false,
        50,
        false,
    )['product'];

    $updatedPayload = microlifeProfessionalCuffPayload();
    $updatedPayload['variant_candidates'] = [$updatedPayload['variant_candidates'][0]];

    $second = $importer->import(
        $updatedPayload,
        ProductStatus::DRAFT,
        false,
        50,
        false,
    )['product'];
    $variants = $second->variants()->get()->keyBy('sku');

    expect($second->id)->toBe($first->id)
        ->and(Product::query()
            ->where('external_source', 'microlife')
            ->where('external_id', $first->external_id)
            ->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants['WATCHBP-OFFICE-ABI-CENTRAL-S']->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variants['WATCHBP-OFFICE-ABI-CENTRAL-S']->is_default)->toBeTrue()
        ->and($variants['WATCHBP-OFFICE-ABI-CENTRAL-M']->status)->toBe(ProductVariantStatus::ARCHIVED)
        ->and($variants['WATCHBP-OFFICE-ABI-CENTRAL-M']->is_default)->toBeFalse();
});

it('downloads Microlife product images and localizes PDF documents', function (): void {
    Storage::fake('public');

    $imageUrl = 'https://www.microlife.pl/uploads/media/600x600/01/bp-b1-standard.png?v=1-0';
    $documentUrl = 'https://www.microlife.pl/uploads/media/downloads/bp-b1-standard-instruction.pdf';
    $imageContents = microlifeImporterTestImageContents();
    $pdfContents = "%PDF-1.4\nMicrolife test document\n%%EOF";

    Http::fake([
        $imageUrl => Http::response($imageContents, 200, ['Content-Type' => 'image/jpeg']),
        $documentUrl => Http::response($pdfContents, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $payload = microlifeConsumerPayload([
        'images' => [[
            'url' => $imageUrl,
            'source_url' => $imageUrl,
            'alt' => 'BP B1 Standard',
            'position' => 0,
            'role' => 'primary',
        ]],
        'downloads' => [[
            'name' => 'Instrukcja obsługi BP B1 Standard',
            'url' => $documentUrl,
            'file_type' => 'PDF',
            'file_size' => null,
        ]],
    ]);

    $result = app(MicrolifeProductImporter::class)->import(
        scraped: $payload,
        status: ProductStatus::DRAFT,
        importImages: true,
        imageLimit: 10,
        importDocuments: true,
        assetTimeoutSeconds: 5,
        assetAttempts: 1,
        assetRetryDelayMs: 0,
        assetRequestDelayMs: 0,
        verifyTls: true,
    );

    $product = $result['product'];
    $image = $product->images()->firstOrFail();

    expect($result['warnings'])->toBe([])
        ->and($image->source_url)->toBe($imageUrl)
        ->and($image->is_main)->toBeTrue()
        ->and($product->description)->toContain('/storage/products/microlife/')
        ->toContain('Instrukcja obsługi BP B1 Standard')
        ->and(Storage::disk('public')->allFiles('products/microlife/'.$product->external_id.'/documents'))
        ->toHaveCount(1);

    Http::assertSent(function (Request $request) use ($imageUrl, $documentUrl): bool {
        if (! in_array($request->url(), [$imageUrl, $documentUrl], true)) {
            return false;
        }

        return $request->hasHeader('Referer', microlifeConsumerUrl());
    });
});

it('runs the Microlife import command with dry-run limit offset and no assets', function (): void {
    $relativePath = 'scrapers/microlife/tests/import-product-data.json';
    $absolutePath = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    file_put_contents($absolutePath, json_encode([
        'source' => 'microlife',
        'products' => [
            microlifeConsumerPayload(),
            microlifeProfessionalCuffPayload(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
        $dryRunCode = Artisan::call('microlife:import', [
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
            ->and($dryRunOutput)->toContain('Variants to create/update: 2')
            ->and($dryRunOutput)->toContain('No database writes were made')
            ->and(Product::query()->where('external_source', 'microlife')->count())->toBe(0);

        $importCode = Artisan::call('microlife:import', [
            '--from' => $relativePath,
            '--offset' => '0',
            '--limit' => '1',
            '--status' => 'draft',
            '--no-images' => true,
            '--no-documents' => true,
            '--show-failures' => true,
        ]);
        $importOutput = Artisan::output();

        expect($importCode)->toBe(0)
            ->and($importOutput)->toContain('Images: skipped')
            ->and($importOutput)->toContain('Documents: source links only')
            ->and($importOutput)->toContain('Imported products: 1')
            ->and($importOutput)->toContain('Failures: 0')
            ->and(Product::query()->where('external_source', 'microlife')->count())->toBe(1);
    } finally {
        @unlink($absolutePath);
    }
});

function microlifeConsumerUrl(): string
{
    return 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b1-standard';
}

function microlifeConsumerPayload(array $overrides = []): array
{
    $url = microlifeConsumerUrl();

    $payload = [
        'source' => 'microlife',
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => hash('sha256', $url),
        'catalogue_type' => 'consumer',
        'slug' => 'bp-b1-standard',
        'name' => 'BP B1 Standard',
        'product_code' => 'BP B1 Standard',
        'brand' => 'Microlife',
        'category' => 'Ciśnieniomierze automatyczne',
        'categories' => ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        'category_paths' => [['Ciśnienie krwi', 'Ciśnieniomierze automatyczne']],
        'seo_title' => 'BP B1 Standard - Microlife',
        'seo_description' => 'Automatyczny ciśnieniomierz naramienny Microlife.',
        'headline' => 'Automatyczny ciśnieniomierz naramienny',
        'short_description' => 'Automatyczny ciśnieniomierz naramienny Microlife.',
        'description' => 'Prosty i dokładny pomiar ciśnienia w domu.',
        'description_html' => '<p>Prosty i dokładny pomiar ciśnienia w domu.</p><script>alert(1)</script><img src="https://example.test/test.jpg">',
        'description_items' => ['Prosty i dokładny pomiar ciśnienia w domu.'],
        'features' => [[
            'title' => 'IHB',
            'description' => 'Wykrywanie nieregularnego bicia serca.',
            'image_url' => null,
        ]],
        'specification_items' => [
            'Pamięć: 30 wyników',
            'Zakres pomiarowy: 20–280 mmHg',
        ],
        'attributes' => [[
            'code' => 'pamiec',
            'label' => 'Pamięć',
            'value' => '30 wyników',
            'slug' => '30-wynikow',
        ]],
        'downloads' => [[
            'name' => 'Instrukcja obsługi BP B1 Standard',
            'url' => 'https://www.microlife.pl/uploads/media/downloads/bp-b1-standard-instruction.pdf',
            'file_type' => 'PDF',
            'file_size' => '1 MB',
        ]],
        'videos' => [[
            'title' => 'BP B1 Standard',
            'url' => 'https://www.youtube.com/watch?v=test',
        ]],
        'images' => [],
        'feature_images' => [],
        'related_products' => [],
        'buy_now_url' => 'https://microlifestore.com/bp-b1-standard',
        'variant_candidates' => [],
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'stock_quantity' => null,
        'sku' => null,
        'ean' => null,
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ];

    return array_replace($payload, $overrides);
}

function microlifeProfessionalCuffPayload(array $overrides = []): array
{
    $url = 'https://www.microlife.pl/produkty-profesjonalne/mankiety-i-wyposazenie/mankiet-kostkowy-watchbp-office';

    $payload = microlifeConsumerPayload([
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => hash('sha256', $url),
        'catalogue_type' => 'professional',
        'slug' => 'mankiet-kostkowy-watchbp-office',
        'name' => 'WatchBP Office ABI/Central',
        'product_code' => 'WatchBP Office ABI/Central',
        'category' => 'Mankiety i wyposażenie',
        'categories' => ['Mankiety i wyposażenie'],
        'category_paths' => [['Mankiety i wyposażenie']],
        'seo_title' => 'WatchBP Office ABI/Central - Microlife',
        'seo_description' => 'Profesjonalny mankiet kostkowy WatchBP Office.',
        'short_description' => 'Profesjonalny mankiet kostkowy WatchBP Office.',
        'description' => 'Mankiet do pomiaru ABI i ciśnienia centralnego.',
        'description_html' => '<p>Mankiet do pomiaru ABI i ciśnienia centralnego.</p>',
        'description_items' => ['Mankiet do pomiaru ABI i ciśnienia centralnego.'],
        'features' => [],
        'specification_items' => [
            'Rozmiar S (14–22 cm)',
            'Rozmiar M (22–32 cm)',
        ],
        'attributes' => [],
        'downloads' => [],
        'videos' => [],
        'buy_now_url' => null,
        'variant_candidates' => [
            microlifeCuffVariant($url, 'S', '14–22 cm'),
            microlifeCuffVariant($url, 'M', '22–32 cm'),
        ],
    ]);

    return array_replace($payload, $overrides);
}

function microlifeCuffVariant(string $url, string $size, string $measurement): array
{
    return [
        'external_variant_id' => hash('sha256', $url.'|size|'.mb_strtolower($size)),
        'sku' => null,
        'product_code' => null,
        'size' => $size,
        'color' => null,
        'model_code' => null,
        'name' => 'WatchBP Office ABI/Central – '.$size,
        'option_values' => [[
            'attribute' => 'Rozmiar',
            'value' => $size,
        ]],
        'measurements' => ['Obwód' => $measurement],
        'measurement_label' => 'Obwód',
        'measurement' => $measurement,
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'stock_quantity' => null,
    ];
}

function microlifeImporterTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Microlife importer test image.');
    }

    try {
        $background = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Microlife importer test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
