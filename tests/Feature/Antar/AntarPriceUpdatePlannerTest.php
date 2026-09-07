<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Antar\AntarPriceUpdatePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('plans a deterministic Antar price and VAT change without writing it', function (): void {
    [$product, $variant] = createAntarPricedProduct('AT01001', net: null, gross: null, vat: VatRate::VAT_23);

    $plan = app(AntarPriceUpdatePlanner::class)->build();
    $row = collect($plan['products'])->firstWhere('product_id', $product->id);

    expect($plan['database_writes'])->toBeFalse()
        ->and($plan['ready_for_price_write'])->toBeTrue()
        ->and($plan['database_summary'])->toMatchArray([
            'products' => 1,
            'default_variants' => 1,
            'matched_products' => 1,
            'changed_products' => 1,
            'unchanged_products' => 0,
            'ambiguous_supplier_price_products' => 0,
        ])
        ->and($row['status'])->toBe('change')
        ->and($row['proposed'])->toBe([
            'price_net_amount' => 25741,
            'price_gross_amount' => 27800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ]);

    $variant->refresh();
    expect($variant->price_net_amount)->toBeNull()
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23);
});

it('recognizes an Antar variant that already matches the supplier price', function (): void {
    createAntarPricedProduct('AT01001', net: 25741, gross: 27800, vat: VatRate::VAT_8);

    $plan = app(AntarPriceUpdatePlanner::class)->build();

    expect($plan['ready_for_price_write'])->toBeTrue()
        ->and($plan['database_summary']['matched_products'])->toBe(1)
        ->and($plan['database_summary']['changed_products'])->toBe(0)
        ->and($plan['database_summary']['unchanged_products'])->toBe(1)
        ->and($plan['products'][0]['status'])->toBe('unchanged');
});

it('refuses to guess among conflicting supplier prices for one Antar code', function (): void {
    $product = createAntarPricedProduct('AT04601 (24)')[0];

    $plan = app(AntarPriceUpdatePlanner::class)->build();
    $row = collect($plan['products'])->firstWhere('product_id', $product->id);

    expect($plan['ready_for_price_write'])->toBeFalse()
        ->and($plan['hard_error_count'])->toBe(0)
        ->and($plan['database_summary']['ambiguous_supplier_price_products'])->toBe(1)
        ->and($row['normalized_supplier_code'])->toBe('AT04601-24')
        ->and($row['status'])->toBe('ambiguous_supplier_price')
        ->and($row['proposed'])->toBeNull()
        ->and($plan['review_items'][0])->toContain('do not guess a price');
});

it('fails closed when an Antar product does not have exactly one live default variant', function (): void {
    $product = Product::query()->create([
        'name' => 'Broken Antar product',
        'slug' => 'broken-antar-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'antar',
        'external_id' => 'broken-antar-product',
        'external_parent_sku' => 'AT01001',
    ]);

    foreach (['A', 'B'] as $suffix) {
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'BROKEN-'.$suffix,
            'status' => ProductVariantStatus::DRAFT,
            'price_net_amount' => null,
            'price_gross_amount' => null,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_8,
            'stock_status' => StockStatus::OUT_OF_STOCK,
            'is_default' => $suffix === 'A',
            'external_variant_id' => 'antar-broken-'.$suffix,
        ]);
    }

    $plan = app(AntarPriceUpdatePlanner::class)->build();

    expect($plan['ready_for_price_write'])->toBeFalse()
        ->and($plan['hard_error_count'])->toBe(1)
        ->and($plan['hard_errors'][0])->toContain('expected exactly one live default Antar variant');
});

it('runs the Antar price update command read-only and saves evidence under storage app', function (): void {
    createAntarPricedProduct('AT01001');
    $relative = 'scrapers/antar/test-price-update-plan.json';
    $path = storage_path('app/'.$relative);
    @unlink($path);

    $this->artisan('antar:price-update-plan', [
        '--save' => $relative,
        '--show-changes' => true,
    ])
        ->expectsOutputToContain('Database writes: NO')
        ->expectsOutputToContain('Supplier priced rows: 995')
        ->expectsOutputToContain('Safe supplier price codes: 930')
        ->expectsOutputToContain('Ambiguous supplier price codes: 10')
        ->expectsOutputToContain('Existing Antar products: 1')
        ->expectsOutputToContain('Matched products: 1')
        ->expectsOutputToContain('Ready for controlled price write: YES')
        ->assertSuccessful();

    expect(is_file($path))->toBeTrue();

    $saved = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['ready_for_price_write'])->toBeTrue();

    @unlink($path);
});

/** @return array{0: Product, 1: ProductVariant} */
function createAntarPricedProduct(
    string $supplierCode,
    ?int $net = null,
    ?int $gross = null,
    VatRate $vat = VatRate::VAT_8,
): array {
    $slug = 'antar-test-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $supplierCode) ?? 'product').'-'.bin2hex(random_bytes(3));

    $product = Product::query()->create([
        'name' => 'Antar '.$supplierCode,
        'slug' => $slug,
        'status' => ProductStatus::DRAFT,
        'external_source' => 'antar',
        'external_id' => $slug,
        'external_parent_sku' => $supplierCode,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => $net,
        'price_gross_amount' => $gross,
        'currency' => Currency::PLN,
        'vat_rate' => $vat,
        'stock_status' => StockStatus::OUT_OF_STOCK,
        'is_default' => true,
        'external_variant_id' => 'antar-'.$slug.'-default',
    ]);

    return [$product, $variant];
}
