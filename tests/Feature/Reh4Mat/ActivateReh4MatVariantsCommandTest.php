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

it('previews priced draft variants without activating them', function (): void {
    $priced = reh4matStatusVariant('AM-OSS-04', 9720);

    $this->artisan('reh4mat:activate-variants')
        ->expectsOutputToContain('TO_ACTIVATE=1')
        ->expectsOutputToContain('ACTIVATE product='.$priced->product_id.' variant='.$priced->id.' [active] Produkt AM-OSS-04 sku=AM-OSS-04 gross 97.20')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($priced->fresh()->status)->toBe(ProductVariantStatus::DRAFT);
});

it('activates priced drafts only and rolls back', function (): void {
    $priced = reh4matStatusVariant('AM-OSS-04', 9720);
    $unpriced = reh4matStatusVariant('FP-07', null);
    $archived = reh4matStatusVariant('OKD-99', 5000, ProductVariantStatus::ARCHIVED);
    $other = reh4matStatusVariant('AT-1', 5000, source: 'antar');

    $this->artisan('reh4mat:activate-variants', ['--write' => true])
        ->expectsOutputToContain('UNPRICED_LEFT_DRAFT=1')
        ->expectsOutputToContain('ACTIVATED=1')
        ->expectsOutputToContain('REH4MAT_ACTIVATE_WRITE=PASS')
        ->assertSuccessful();

    expect($priced->fresh()->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($unpriced->fresh()->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($archived->fresh()->status)->toBe(ProductVariantStatus::ARCHIVED)
        ->and($other->fresh()->status)->toBe(ProductVariantStatus::DRAFT);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('reh4mat:activate-variants', ['--rollback' => $backup])
        ->expectsOutputToContain('RETURNED_TO_DRAFT=1 OF 1')
        ->assertSuccessful();

    expect($priced->fresh()->status)->toBe(ProductVariantStatus::DRAFT);
});

it('shows the price and cart once the variant is active', function (): void {
    $variant = reh4matStatusVariant('AM-OSS-04', 9720);

    $this->get('/products/'.$variant->product->slug)->assertSee('Cena niedostępna');

    $this->artisan('reh4mat:activate-variants', ['--write' => true])->assertSuccessful();

    $this->get('/products/'.$variant->product->slug)
        ->assertDontSee('Cena niedostępna')
        ->assertSee('97,20');
});

function reh4matStatusVariant(string $sku, ?int $grossAmount, ProductVariantStatus $status = ProductVariantStatus::DRAFT, string $source = 'reh4mat'): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => str($sku)->slug()->toString(),
        'external_parent_sku' => $sku,
        'name' => 'Produkt '.$sku,
        'slug' => str('produkt-'.$sku)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'status' => $status,
        'price_net_amount' => $grossAmount === null ? null : VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
}
