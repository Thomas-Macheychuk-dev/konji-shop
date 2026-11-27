<?php

use App\Console\Commands\ApplyMedi2027PricesCommand;
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

beforeEach(fn () => Storage::fake('local'));

it('prices listed medi products at the 2027 wholesale net plus VAT plus 20% in whole złoty', function (): void {
    // Genumedi: 148 zł net, extra-wide thigh (K1032…) 184 zł net; 148 x 1.08 x 1.2 = 191.81 → 192 zł.
    // The extra-wide variant was imported at 23% VAT and is priced and taxed at 8%.
    $genumedi = Product::query()->create(['external_source' => 'medi', 'external_id' => 'genumedi', 'name' => 'Genumedi®', 'slug' => 'genumedi', 'status' => ProductStatus::ACTIVE]);
    $regular = medi2027Variant($genumedi, 'K102218000', 22000);
    $wide = medi2027Variant($genumedi, 'K103288000', 22000, VatRate::VAT_23);
    $compression = Product::query()->create(['external_source' => 'medi', 'external_id' => 'duomed', 'name' => 'duomed®', 'slug' => 'duomed', 'status' => ProductStatus::ACTIVE]);
    $unlisted = medi2027Variant($compression, 'V110015007', 25200);

    expect(ApplyMedi2027PricesCommand::shopGross(182, VatRate::VAT_8))->toBe(23600)
        ->and(ApplyMedi2027PricesCommand::shopGross(98, VatRate::VAT_8))->toBe(12700);

    $this->artisan('medi:apply-2027-prices')
        ->expectsOutputToContain('IN_SHOP=1 PRICE_CHANGES=2 VAT_TO_8=1 ALREADY_CURRENT=0 SKIPPED=0')
        ->expectsOutputToContain('Genumedi® (Genumedi K10221/K10281): 220.00 -> 192.00-238.00, VAT 8%/23% -> 8%, 2 variants')
        ->expectsOutputToContain('NOT_IN_SHOP Achimed')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($regular->fresh()->price_gross_amount)->toBe(22000);

    $this->artisan('medi:apply-2027-prices', ['--write' => true])->expectsOutputToContain('WRITTEN_PRICES=2')->assertSuccessful();

    expect($regular->fresh()->price_gross_amount)->toBe(19200)
        ->and($regular->fresh()->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(19200))
        ->and($wide->fresh()->price_gross_amount)->toBe(23800)
        ->and($wide->fresh()->vat_rate)->toBe(VatRate::VAT_8)
        ->and($wide->fresh()->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(23800))
        ->and($unlisted->fresh()->price_gross_amount)->toBe(25200);

    $this->artisan('medi:apply-2027-prices')->expectsOutputToContain('PRICE_CHANGES=0 VAT_TO_8=0 ALREADY_CURRENT=2')->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('medi:apply-2027-prices', ['--rollback' => $backup])->expectsOutputToContain('RESTORED_PRICES=2 OF 2')->assertSuccessful();

    expect($regular->fresh()->price_gross_amount)->toBe(22000)
        ->and($wide->fresh()->price_gross_amount)->toBe(22000)
        ->and($wide->fresh()->vat_rate)->toBe(VatRate::VAT_23);
});

function medi2027Variant(Product $product, string $sku, int $grossAmount, VatRate $vat = VatRate::VAT_8): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'external_variant_id' => 'medi-'.$sku,
        'sku' => $sku,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => $vat->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => $vat,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => $sku === 'K102218000',
    ]);
}
