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

it('previews list prices for matched variants without writing', function (): void {
    $variant = reh4matPricedVariant('AM-OSS-04', 'Orteza stawu skokowego AM-OSS-04', 10000);

    $this->artisan('reh4mat:apply-price-list')
        ->expectsOutputToContain('LIST=CENNIK 2026 01-06.pdf EFFECTIVE=2026-06-01')
        ->expectsOutputToContain('PRICE_CHANGES=1 (UP=0 DOWN=1 FROM_EMPTY=0)')
        ->expectsOutputToContain('variant='.$variant->id.' [active] Orteza stawu skokowego AM-OSS-04 code=AM-OSS-04 [sku] gross 100.00 -> 97.20 (net 92.59 -> 90.00, VAT 8%)')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($variant->fresh()->price_gross_amount)->toBe(10000);
});

it('writes net and gross prices, keeps a backup and rolls back', function (): void {
    $variant = reh4matPricedVariant('AM-OSS-04', 'Orteza stawu skokowego AM-OSS-04', 10000);
    $current = reh4matPricedVariant('AM-OSS-02', 'Orteza AM-OSS-02', 9180);
    $current->forceFill(['price_net_amount' => 8500])->save();

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])
        ->expectsOutputToContain('ALREADY_CURRENT=1')
        ->expectsOutputToContain('WRITTEN_PRICES=1')
        ->expectsOutputToContain('REH4MAT_PRICE_WRITE=PASS')
        ->assertSuccessful();

    expect($variant->fresh()->only(['price_net_amount', 'price_gross_amount']))
        ->toEqual(['price_net_amount' => 9000, 'price_gross_amount' => 9720]);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('reh4mat:apply-price-list', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRICES=1 OF 1')
        ->assertSuccessful();

    expect($variant->fresh()->only(['price_net_amount', 'price_gross_amount']))
        ->toEqual(['price_net_amount' => 9259, 'price_gross_amount' => 10000]);
});

it('picks the adult or children price for a shared symbol', function (): void {
    $adult = reh4matPricedVariant('AM-OSS-03', 'Sznurowana orteza stawu skokowego AM-OSS-03', 10000);
    $child = reh4matPricedVariant('AM-OSS-03', 'Dziecięca sznurowana orteza stawu skokowego AM-OSS-03', 10000, 'am-oss-03-dzieciecy');

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])->assertSuccessful();

    expect($adult->fresh()->price_net_amount)->toBe(11500)
        ->and($child->fresh()->price_net_amount)->toBe(13000);
});

it('matches size and model suffixes exactly and ignores accessory rows', function (): void {
    $regular = reh4matPricedVariant('AS-KX-08', 'Orteza kolana AS-KX-08', 10000);
    $xSize = reh4matPricedVariant('AS-KX-08 X-size', 'Orteza kolana AS-KX-08 X-size', 10000, 'as-kx-08-x-size');
    $hip = reh4matPricedVariant('AM-ADS-R', 'Orteza biodra AM-ADS-R', 10000, 'am-ads-r');

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])->assertSuccessful();

    expect($regular->fresh()->price_net_amount)->toBe(19000)
        ->and($xSize->fresh()->price_net_amount)->toBe(23000)
        ->and($hip->fresh()->price_net_amount)->toBe(35000);
});

it('falls back to the code in the product name when the SKU is the import placeholder', function (): void {
    $variant = reh4matPricedVariant('REH4MAT-1188', 'Orteza nadgarstka AM-OSN-U-02', 10000);

    $this->artisan('reh4mat:apply-price-list')
        ->expectsOutputToContain('code=AM-OSN-U-02 [name] gross 100.00 -> 194.40')
        ->assertSuccessful();
});

it('prices accessories named after a product from their own rows', function (): void {
    $cover = reh4matPricedVariant('AM-ADS-R', 'Pokrowiec do AM-ADS-R', 10000, 'pokrowiec-am-ads-r');
    $mount = reh4matPricedVariant('REH4MAT-1', 'Zestaw mocujący do ortezy AM-OSS-21/CCA', 10000, 'zestaw-am-oss-21');
    $stiffener = reh4matPricedVariant('AM-D-03', 'Dodatkowe usztywnienie palca do ortezy AM-D-03', 10000, 'usztywnienie-am-d-03');
    $unlisted = reh4matPricedVariant('AM-SB-01', 'Gąbka do AM-SB-01', 10000, 'gabka-am-sb-01');

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])
        ->expectsOutputToContain('NOT_IN_LIST product='.$unlisted->product_id)
        ->assertSuccessful();

    expect($cover->fresh()->price_net_amount)->toBe(8600)
        ->and($mount->fresh()->price_net_amount)->toBe(1500)
        ->and($stiffener->fresh()->price_net_amount)->toBe(1700)
        ->and($unlisted->fresh()->price_gross_amount)->toBe(10000);
});

it('matches SKUs written with other separators and codes after a slash in the name', function (): void {
    $attack = reh4matPricedVariant('ATTACK-OA', 'ATTACK OA', 10000);
    $atom = reh4matPricedVariant('ATOM-ACL-CCA', 'Orteza kończyny dolnej ATOM ACL/CCA', 10000);
    $named = reh4matPricedVariant('ATTACK-2RA', 'Orteza odciążająca staw kolanowy / ATTACK 2RA OA', 10000);
    $model = reh4matPricedVariant('REH4MAT-124686', 'Kombiwar', 10000, 'kombiwar');

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])->assertSuccessful();

    expect($attack->fresh()->price_net_amount)->toBe(68000)
        ->and($atom->fresh()->price_net_amount)->toBe(85000)
        ->and($named->fresh()->price_net_amount)->toBe(49000)
        ->and($model->fresh()->price_net_amount)->toBe(38500);
});

it('uses the separately priced colour version when the product name has that colour', function (): void {
    $grey = reh4matPricedVariant('EB-SK-A', 'Orteza kończyny dolnej EB-SK/A SZARY', 10000, 'eb-sk-a-szary');
    $beige = reh4matPricedVariant('EB-SK-A', 'Orteza kończyny dolnej EB-SK/A BEŻ', 10000, 'eb-sk-a-bez');
    $black = reh4matPricedVariant('AM-PES-03', 'Orteza tułowia AM-PES-03 CZARNY', 10000, 'am-pes-03-czarny');
    $greyBack = reh4matPricedVariant('AM-PES-03', 'Orteza tułowia AM-PES-03 SZARY', 10000, 'am-pes-03-szary');

    $this->artisan('reh4mat:apply-price-list')
        ->expectsOutputToContain('code=EB-SK/A BEŻ [parent-sku+colour]')
        ->assertSuccessful();

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])->assertSuccessful();

    expect($grey->fresh()->price_net_amount)->toBe(29000)
        ->and($beige->fresh()->price_net_amount)->toBe(25200)
        ->and($black->fresh()->price_net_amount)->toBe(5400)
        ->and($greyBack->fresh()->price_net_amount)->toBe(6500);
});

it('reports ambiguous and unknown products and leaves them and other suppliers alone', function (): void {
    $ambiguous = reh4matPricedVariant('FIX-T-01', 'Dziecięca kamizelka sensoryczna FIX-T-01', 10000);
    $unknown = reh4matPricedVariant('XYZ-1', 'Pokrowiec elastyczny', 10000);
    $other = reh4matPricedVariant('AM-OSS-04', 'Orteza AM-OSS-04 innego dostawcy', 10000, 'other', 'antar');

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])
        ->expectsOutputToContain('AMBIGUOUS=1')
        ->expectsOutputToContain('NOT_IN_LIST=1')
        ->expectsOutputToContain('AMBIGUOUS product='.$ambiguous->product_id)
        ->expectsOutputToContain('NOT_IN_LIST product='.$unknown->product_id)
        ->expectsOutputToContain('NOTHING_TO_WRITE')
        ->assertSuccessful();

    expect([$ambiguous->fresh()->price_gross_amount, $unknown->fresh()->price_gross_amount, $other->fresh()->price_gross_amount])
        ->toBe([10000, 10000, 10000]);
});

function reh4matPricedVariant(string $sku, string $name, int $grossAmount, ?string $slug = null, string $source = 'reh4mat'): ProductVariant
{
    $slug ??= str($sku)->slug()->toString();

    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => $slug,
        'external_parent_sku' => $sku,
        'name' => $name,
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        // The importer makes duplicate SKUs unique the same way, leaving the parent SKU as the code.
        'sku' => $sku.(ProductVariant::query()->where('sku', $sku)->exists() ? '-'.$product->id : ''),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
}
