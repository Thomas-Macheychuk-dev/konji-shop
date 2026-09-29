<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('reports Iconic exclusions in dry-run and blocks database import before writes when VAT is unresolved', function (): void {
    Storage::fake('local');

    $priced = iconicPricedFixture();

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

    $priced = iconicPricedFixture();

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
