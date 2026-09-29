<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function seni24CommandFixture(): array
{
    return [
        'source' => 'seni24',
        'source_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'canonical_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'external_product_id' => '338',
        'slug' => 'kubek-pojnik-z-ustnikiem-200-ml',
        'name' => 'Kubek pojnik z ustnikiem 200 ml',
        'price_gross_amount' => 9.39,
        'currency' => 'PLN',
        'vat_rate' => 8,
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny',
        'is_on_order' => false,
        'shipping_time' => '24-48h',
        'unit' => 'szt.',
        'catalogue_number' => 'NN-SRW-AHK1-001',
        'ean' => '5905279578104',
        'source_category_path' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'categories' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'category' => 'Akcesoria kuchenne',
        'description_html' => '<p>Opis produktu.</p>',
        'description_plain' => 'Opis produktu.',
        'images' => [],
        'attributes' => [
            ['label' => 'Indeks', 'value' => 'NN-SRW-AHK1-001'],
            ['label' => 'ean13', 'value' => '5905279578104'],
        ],
        'variant_candidates' => [[
            'external_variant_id' => '16501',
            'label' => 'Pojemność: 200 ml',
            'attributes' => [['label' => 'Pojemność', 'value' => '200 ml']],
            'price_gross_amount' => 9.39,
            'currency' => 'PLN',
            'vat_rate' => 8,
        ]],
        'variants_unresolved' => false,
        'is_medical_device' => true,
        'medical_device_class' => null,
        'raw_context' => [
            'listing_roots' => ['https://www.seni24.pl/strona-glowna/'],
        ],
    ];
}

it('excludes unresolved Seni24 variants during dry-run and writes nothing', function (): void {
    Storage::fake('local');

    $resolved = seni24CommandFixture();
    $unresolved = $resolved;
    $unresolved['external_product_id'] = '10858';
    $unresolved['slug'] = 'deomed-wool';
    $unresolved['name'] = 'DeoMed Wool';
    $unresolved['variants_unresolved'] = true;

    Storage::disk('local')->put(
        'scrapers/seni24/test-products.json',
        json_encode([
            'source' => 'seni24',
            'products' => [$resolved, $unresolved],
        ], JSON_THROW_ON_ERROR),
    );

    $exit = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Source products: 2')
        ->and($output)->toContain('Commerce-eligible priced products: 1')
        ->and($output)->toContain('Excluded on-order/unpriced/unresolved-variant products: 1')
        ->and($output)->toContain('Products to import/update: 1')
        ->and(Product::query()->where('external_source', 'seni24')->count())->toBe(0);
});

it('imports a resolved Seni24 product using its scraped VAT rate', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put(
        'scrapers/seni24/test-products.json',
        json_encode([
            'source' => 'seni24',
            'products' => [seni24CommandFixture()],
        ], JSON_THROW_ON_ERROR),
    );

    $exit = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--no-images' => true,
    ]);

    $variant = ProductVariant::query()
        ->whereHas('product', fn ($query) => $query->where('external_source', 'seni24'))
        ->firstOrFail();

    expect($exit)->toBe(0)
        ->and(Product::query()->where('external_source', 'seni24')->count())->toBe(1)
        ->and($variant->vat_rate->value)->toBe(8);
});
