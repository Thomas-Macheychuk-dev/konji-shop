<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('previews the median shop price for unpriced variants without writing', function (): void {
    $variant = reh4matOnlineVariant('P-SS-24', 'P-SS-24 Poduszka przeciwodleżynowa');
    $file = reh4matOnlinePriceFile([
        ['sku' => 'P-SS-24', 'sources' => [['gross_pln' => 153.00], ['gross_pln' => 124.69], ['gross_pln' => 139.90]]],
    ]);

    $this->artisan('reh4mat:apply-online-prices', ['--file' => $file])
        ->expectsOutputToContain('PRICE_CHANGES=1')
        ->expectsOutputToContain('variant='.$variant->id.' [active] P-SS-24 Poduszka przeciwodleżynowa sku=P-SS-24 gross (empty) -> 139.90 (net 129.54, VAT 8%) median of 124.69 / 139.90 / 153.00')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($variant->fresh()->price_gross_amount)->toBeNull();
});

it('writes the median, keeps list prices and rolls back', function (): void {
    $cushion = reh4matOnlineVariant('P-SS-21', 'P-SS-21 Poduszka silikonowa');
    $listed = reh4matOnlineVariant('AM-SO-02', 'Orteza AM-SO-02', 18900);
    $unpriced = reh4matOnlineVariant('FP-01', 'FP-01 Kamizelka');
    $file = reh4matOnlinePriceFile([
        ['sku' => 'P-SS-21', 'sources' => [['gross_pln' => 119.00], ['gross_pln' => 94.34]]],
        ['sku' => 'AM-SO-02', 'sources' => [['gross_pln' => 150.00]]],
        ['sku' => 'FP-01', 'sources' => []],
        ['sku' => 'NOT-A-SKU', 'sources' => [['gross_pln' => 10.00]]],
    ]);

    $this->artisan('reh4mat:apply-online-prices', ['--file' => $file, '--write' => true])
        ->expectsOutputToContain('ALREADY_PRICED=1')
        ->expectsOutputToContain('NO_ONLINE_PRICE=1')
        ->expectsOutputToContain('SKU_NOT_FOUND=1')
        ->expectsOutputToContain('WRITTEN_PRICES=1')
        ->expectsOutputToContain('REH4MAT_ONLINE_PRICE_WRITE=PASS')
        ->assertSuccessful();

    // Median of two prices is their mean: (119.00 + 94.34) / 2 = 106.67.
    expect($cushion->fresh()->only(['price_net_amount', 'price_gross_amount']))
        ->toEqual(['price_net_amount' => 9877, 'price_gross_amount' => 10667])
        ->and($listed->fresh()->price_gross_amount)->toBe(18900)
        ->and($unpriced->fresh()->price_gross_amount)->toBeNull();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('reh4mat:apply-online-prices', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRICES=1 OF 1')
        ->assertSuccessful();

    expect($cushion->fresh()->price_gross_amount)->toBeNull();
});

it('only prices Reh4Mat variants', function (): void {
    $other = reh4matOnlineVariant('P-SS-24', 'Poduszka innego dostawcy', source: 'antar');
    $file = reh4matOnlinePriceFile([['sku' => 'P-SS-24', 'sources' => [['gross_pln' => 153.00]]]]);

    $this->artisan('reh4mat:apply-online-prices', ['--file' => $file, '--write' => true])
        ->expectsOutputToContain('SKU_NOT_FOUND=1')
        ->expectsOutputToContain('NOTHING_TO_WRITE')
        ->assertSuccessful();

    expect($other->fresh()->price_gross_amount)->toBeNull();
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function reh4matOnlinePriceFile(array $products): string
{
    $path = tempnam(sys_get_temp_dir(), 'reh4mat-online-').'.json';
    file_put_contents($path, json_encode([
        'collected_at' => '2026-11-04',
        'currency' => 'PLN',
        'price_basis' => 'gross',
        'products' => $products,
    ]));

    return $path;
}

function reh4matOnlineVariant(string $sku, string $name, ?int $grossAmount = null, string $source = 'reh4mat'): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => str($sku)->slug()->toString(),
        'external_parent_sku' => $sku,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => $grossAmount === null ? null : VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
}
