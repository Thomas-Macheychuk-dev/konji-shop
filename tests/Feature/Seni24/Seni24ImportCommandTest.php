<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('excludes unresolved Seni24 variants during dry-run and writes nothing', function (): void {
    Storage::fake('local');

    $resolved = seni24PricedFixture();
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
            'products' => [seni24PricedFixture()],
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
