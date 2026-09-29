<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function iconicCommandFixture(): array
{
    return [
        'source' => 'iconic',
        'source_url' => 'https://sklep.iconic.pl/produkty/relief-dual.html',
        'canonical_url' => 'https://sklep.iconic.pl/produkty/relief-dual.html',
        'external_product_id' => 'relief-dual',
        'slug' => 'relief-dual',
        'name' => 'But Pooperacyjny DARCO - Relief Dual',
        'price_gross_amount' => 176.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'is_on_order' => false,
        'shipping_time' => '24 h - 2 dni',
        'unit' => 'sztuka',
        'catalogue_number' => 'RD-M1',
        'source_category_path' => [
            'Zaopatrzenie ortopedyczne stopy',
            'DARCO - Obuwie odciążające i pooperacyjne',
        ],
        'categories' => [
            'Zaopatrzenie ortopedyczne stopy',
            'DARCO - Obuwie odciążające i pooperacyjne',
        ],
        'description_html' => '<p>Opis produktu.</p>',
        'description_plain' => 'Opis produktu.',
        'images' => [],
        'attributes' => [
            ['label' => 'Numer katalogowy', 'value' => 'RD-M1'],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '1195',
                'label' => 'Rozmiar: MS',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'MS'],
                ],
                'price_gross_amount' => 176.0,
                'currency' => 'PLN',
            ],
        ],
        'is_medical_device' => true,
        'medical_device_class' => 'I',
        'raw_context' => [
            'listing_roots' => [
                'https://sklep.iconic.pl/produkty/zaopatrzenie-ortopedyczne-stopy',
            ],
        ],
    ];
}

it('reports Iconic exclusions in dry-run and blocks database import before writes when VAT is unresolved', function (): void {
    Storage::fake('local');
    config()->set('iconic.default_vat_rate', null);

    $priced = iconicCommandFixture();

    $onOrder = $priced;
    $onOrder['external_product_id'] = 'regeneracja-narzedzi';
    $onOrder['slug'] = 'regeneracja-narzedzi';
    $onOrder['name'] = 'Regeneracja narzędzi';
    $onOrder['price_gross_amount'] = null;
    $onOrder['is_on_order'] = true;
    $onOrder['availability'] = 'on_order';
    $onOrder['variant_candidates'] = [];

    Storage::disk('local')->put(
        'scrapers/iconic/test-products.json',
        json_encode([
            'source' => 'iconic',
            'products' => [$priced, $onOrder],
        ], JSON_THROW_ON_ERROR),
    );

    $dryRunExit = Artisan::call('iconic:import', [
        '--from' => 'scrapers/iconic/test-products.json',
        '--dry-run' => true,
    ]);
    $dryRunOutput = Artisan::output();

    expect($dryRunExit)->toBe(0)
        ->and($dryRunOutput)->toContain('Source products: 2')
        ->and($dryRunOutput)->toContain('Commerce-eligible priced products: 1')
        ->and($dryRunOutput)->toContain('Excluded on-order/unpriced products: 1')
        ->and($dryRunOutput)->toContain('Products without explicit VAT after override: 1')
        ->and(Product::query()->where('external_source', 'iconic')->count())->toBe(0);

    $blockedExit = Artisan::call('iconic:import', [
        '--from' => 'scrapers/iconic/test-products.json',
        '--no-images' => true,
    ]);
    $blockedOutput = Artisan::output();

    expect($blockedExit)->toBe(1)
        ->and($blockedOutput)->toContain('Iconic import blocked before database writes')
        ->and(Product::query()->where('external_source', 'iconic')->count())->toBe(0);
});

it('imports only priced Iconic products when an explicit VAT override is supplied', function (): void {
    Storage::fake('local');

    $priced = iconicCommandFixture();

    $onOrder = $priced;
    $onOrder['external_product_id'] = 'regeneracja-narzedzi';
    $onOrder['slug'] = 'regeneracja-narzedzi';
    $onOrder['name'] = 'Regeneracja narzędzi';
    $onOrder['price_gross_amount'] = null;
    $onOrder['is_on_order'] = true;
    $onOrder['availability'] = 'on_order';
    $onOrder['variant_candidates'] = [];

    Storage::disk('local')->put(
        'scrapers/iconic/test-products.json',
        json_encode([
            'source' => 'iconic',
            'products' => [$priced, $onOrder],
        ], JSON_THROW_ON_ERROR),
    );

    $exit = Artisan::call('iconic:import', [
        '--from' => 'scrapers/iconic/test-products.json',
        '--vat-rate' => '23',
        '--no-images' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(Product::query()->where('external_source', 'iconic')->count())->toBe(1)
        ->and(Product::query()->where('external_id', 'regeneracja-narzedzi')->exists())->toBeFalse();
});


it('uses the configured default Iconic VAT rate when no CLI override is supplied', function (): void {
    Storage::fake('local');
    config()->set('iconic.default_vat_rate', 8);

    Storage::disk('local')->put(
        'scrapers/iconic/test-products.json',
        json_encode([
            'source' => 'iconic',
            'products' => [iconicCommandFixture()],
        ], JSON_THROW_ON_ERROR),
    );

    $exit = Artisan::call('iconic:import', [
        '--from' => 'scrapers/iconic/test-products.json',
        '--no-images' => true,
    ]);

    $variant = ProductVariant::query()
        ->whereHas('product', fn ($query) => $query->where('external_source', 'iconic'))
        ->firstOrFail();

    expect($exit)->toBe(0)
        ->and($variant->vat_rate->value)->toBe(8);
});
