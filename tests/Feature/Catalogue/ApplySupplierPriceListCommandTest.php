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

it('previews Timago prices with the 10% markup without writing', function (): void {
    $collar = supplierPricedVariant('timago', '88', 'Kołnierz miękki typu SCHANTZA', null);
    supplierPricedVariant('timago', 'not-listed', 'Produkt spoza cennika', 5000);

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'timago-2026'])
        ->expectsOutputToContain('SUPPLIER=timago')
        ->expectsOutputToContain('PRICE_CHANGES=1 (UP=0 DOWN=0 FROM_EMPTY=1)')
        ->expectsOutputToContain('variant='.$collar->id.' [active] Kołnierz miękki typu SCHANTZA [Ortotyka:6] gross (empty) -> 18.89 (net (empty) -> 17.49, VAT 8%)')
        ->expectsOutputToContain('NOT_IN_LIST=1')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($collar->fresh()->price_gross_amount)->toBeNull();
});

it('writes Vermeiren retail prices, keeps a backup and rolls back', function (): void {
    $wheelchair = supplierPricedVariant('vermeiren', '8d21f4002841ec6fc25ce5210dd2268eaba04660fd2a9346ae778d56fe13bde5', '101', 70000);

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'vermeiren-2026', '--write' => true])
        ->expectsOutputToContain('WRITTEN_PRICES=1')
        ->expectsOutputToContain('SUPPLIER_PRICE_WRITE=PASS')
        ->assertSuccessful();

    expect($wheelchair->fresh()->only(['price_net_amount', 'price_gross_amount']))
        ->toEqual(['price_net_amount' => 60185, 'price_gross_amount' => 65000]);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:apply-supplier-prices', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRICES=1 OF 1')
        ->assertSuccessful();

    expect($wheelchair->fresh()->price_gross_amount)->toBe(70000);
});

it('applies the list VAT rate and restores the old one on rollback', function (): void {
    // The wheelchair tray is listed with 23% VAT; the shop variant has 8%.
    $table = supplierPricedVariant('timago', '819', 'TABLE - Stolik do wózka inwalidzkiego', 5000);

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'timago-2026'])
        ->expectsOutputToContain('VAT_CHANGES=1')
        ->expectsOutputToContain('[Rehabilitacja:51] gross 50.00 -> 70.36 (net 46.30 -> 57.20, VAT 8% -> 23%)')
        ->assertSuccessful();

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'timago-2026', '--write' => true])
        ->expectsOutputToContain('WRITTEN_PRICES=1')
        ->assertSuccessful();

    expect($table->fresh()->only(['price_net_amount', 'price_gross_amount', 'vat_rate']))
        ->toEqual(['price_net_amount' => 5720, 'price_gross_amount' => 7036, 'vat_rate' => VatRate::VAT_23]);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:apply-supplier-prices', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRICES=1 OF 1')
        ->assertSuccessful();

    expect($table->fresh()->only(['price_gross_amount', 'vat_rate']))
        ->toEqual(['price_gross_amount' => 5000, 'vat_rate' => VatRate::VAT_8]);
});

it('sees every variant when a later-created variant ends a chunk', function (): void {
    // Products 1-199 are not in the list; product 200 (the collar) is. Variant 201 belongs to product 199, so
    // ordered by product it closes the first chunk of 200: paging by product_id would then skip variant 200.
    for ($n = 1; $n <= 199; $n++) {
        $filler = supplierPricedVariant('timago', 'filler-'.$n, 'Produkt '.$n, 5000);
    }
    $collar = supplierPricedVariant('timago', '88', 'Kołnierz miękki typu SCHANTZA', null);
    ProductVariant::query()->create([
        'product_id' => $filler->product_id,
        'sku' => 'FILLER-SIZE-2',
        'status' => ProductVariantStatus::ACTIVE,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
    ]);

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'timago-2026'])
        ->expectsOutputToContain('variant='.$collar->id.' [active] Kołnierz miękki typu SCHANTZA [Ortotyka:6] gross (empty) -> 18.89')
        ->expectsOutputToContain('NOT_IN_LIST=200')
        ->assertSuccessful();
});

it('keeps the variant VAT rate for gross-only online prices', function (): void {
    // HOLD-TIM is priced at the 43.00 median of shop offers; the table carries no VAT rate.
    $bar = supplierPricedVariant('timago', '1092', 'Uchwyt łazienkowy prosty - HOLD-TIM', null);
    $bar->update(['vat_rate' => VatRate::VAT_23]);

    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'timago-online-2026', '--write' => true])
        ->expectsOutputToContain('gross (empty) -> 43.00 (net (empty) -> 34.96, VAT 23%)')
        ->expectsOutputToContain('VAT_CHANGES=0')
        ->expectsOutputToContain('WRITTEN_PRICES=1')
        ->assertSuccessful();

    expect($bar->fresh()->only(['price_net_amount', 'price_gross_amount', 'vat_rate']))
        ->toEqual(['price_net_amount' => 3496, 'price_gross_amount' => 4300, 'vat_rate' => VatRate::VAT_23]);
});

it('rejects an unknown price table', function (): void {
    $this->artisan('catalogue:apply-supplier-prices', ['list' => 'nope'])
        ->expectsOutputToContain('PRICE_LIST_ERROR=Choose one of: timago-2026, timago-online-2026, vermeiren-2026, vermeiren-online-2026')
        ->assertFailed();
});

function supplierPricedVariant(string $source, string $externalId, string $name, ?int $grossAmount): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => $externalId,
        'name' => $name,
        'slug' => str($source.'-'.$externalId)->slug()->limit(80, '')->toString(),
        'status' => ProductStatus::ACTIVE,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => strtoupper($source).'-'.$product->id,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => $grossAmount === null ? null : VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
}
