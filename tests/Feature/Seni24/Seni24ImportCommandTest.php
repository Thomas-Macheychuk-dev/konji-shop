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
        'source_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
        'canonical_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
        'external_product_id' => '24170',
        'slug' => 'seni-super-test',
        'name' => 'Seni Super test',
        'brand' => 'Seni',
        'price_gross_amount' => 19.25,
        'currency' => 'PLN',
        'vat_rate' => 5,
        'availability' => 'in_stock',
        'source_category_path' => [
            'Nietrzymanie moczu',
            'Pieluchomajtki',
        ],
        'description_html' => '<p>Opis produktu.</p>',
        'description_plain' => 'Opis produktu.',
        'images' => [],
        'attributes' => [],
        'variant_resolution_complete' => true,
        'variant_candidates' => [
            [
                'external_variant_id' => '26340',
                'label' => 'Rozmiar: XS',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'XS'],
                ],
                'price_gross_amount' => 19.25,
                'currency' => 'PLN',
                'vat_rate' => 5,
                'availability' => 'in_stock',
            ],
        ],
        'is_medical_device' => true,
        'is_refundable' => true,
        'raw_context' => [
            'listing_roots' => [
                'https://www.seni24.pl/nietrzymanie-moczu/',
            ],
        ],
    ];
}

it('blocks the whole Seni24 import before writes when any selected product is incomplete', function (): void {
    Storage::fake('local');

    $complete = seni24CommandFixture();
    $incomplete = $complete;
    $incomplete['external_product_id'] = '24171';
    $incomplete['canonical_url'] = 'https://www.seni24.pl/seni-incomplete_24171-26350';
    $incomplete['source_url'] = $incomplete['canonical_url'];
    $incomplete['name'] = 'Seni incomplete';
    $incomplete['variant_resolution_complete'] = false;
    $incomplete['variant_candidates'][0]['external_variant_id'] = '26350';

    Storage::disk('local')->put(
        'scrapers/seni24/test-products.json',
        json_encode([
            'source' => 'seni24',
            'products' => [$complete, $incomplete],
        ], JSON_THROW_ON_ERROR),
    );

    $dryRunExit = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--dry-run' => true,
    ]);
    $dryRunOutput = Artisan::output();

    expect($dryRunExit)->toBe(1)
        ->and($dryRunOutput)->toContain('Source products: 2')
        ->and($dryRunOutput)->toContain('Selected products: 2')
        ->and($dryRunOutput)->toContain('Preflight blockers: 1')
        ->and($dryRunOutput)->toContain('variant matrix is incomplete')
        ->and(Product::query()->where('external_source', 'seni24')->count())->toBe(0);

    $blockedExit = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--no-images' => true,
    ]);
    $blockedOutput = Artisan::output();

    expect($blockedExit)->toBe(1)
        ->and($blockedOutput)->toContain('Seni24 import blocked before database writes')
        ->and(Product::query()->where('external_source', 'seni24')->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports a fully resolved Seni24 dataset using source VAT without a fallback', function (): void {
    Storage::fake('local');
    config()->set('seni24.default_vat_rate', null);

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
    $output = Artisan::output();

    $variant = ProductVariant::query()
        ->whereHas(
            'product',
            fn ($query) => $query->where('external_source', 'seni24'),
        )
        ->firstOrFail();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Preflight blockers: 0')
        ->and($output)->toContain('Imported products: 1')
        ->and($variant->vat_rate->value)->toBe(5);
});

it('accepts an explicit Seni24 VAT fallback only when source VAT is absent', function (): void {
    Storage::fake('local');

    $fixture = seni24CommandFixture();
    $fixture['vat_rate'] = null;
    $fixture['variant_candidates'][0]['vat_rate'] = null;

    Storage::disk('local')->put(
        'scrapers/seni24/test-products.json',
        json_encode([
            'source' => 'seni24',
            'products' => [$fixture],
        ], JSON_THROW_ON_ERROR),
    );

    $blocked = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--no-images' => true,
    ]);

    expect($blocked)->toBe(1)
        ->and(Product::query()->where('external_source', 'seni24')->count())->toBe(0);

    $exit = Artisan::call('seni24:import', [
        '--from' => 'scrapers/seni24/test-products.json',
        '--vat-rate' => '8',
        '--no-images' => true,
    ]);

    $variant = ProductVariant::query()
        ->whereHas(
            'product',
            fn ($query) => $query->where('external_source', 'seni24'),
        )
        ->firstOrFail();

    expect($exit)->toBe(0)
        ->and($variant->vat_rate->value)->toBe(8);
});
