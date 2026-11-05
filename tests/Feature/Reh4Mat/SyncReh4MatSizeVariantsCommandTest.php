<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Reh4Mat\Reh4MatSizeOptions;
use App\Services\Reh4Mat\Reh4MatSizeVariantSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

const REH4MAT_ATTACK_PRO_DESCRIPTION = '<table><tbody><tr><td>Rozmiar</td><td>Obwód kolana</td></tr>'
    .'<tr><td>S</td><td>30-34 cm</td></tr><tr><td>M</td><td>34-38 cm</td></tr><tr><td>L</td><td>38-42 cm</td></tr>'
    .'</tbody></table><p><strong>Występuje jako prawy / lewy .</strong></p>';

function reh4matSizedProduct(string $sku, ?string $description, ?int $grossAmount = 64800): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => 'reh4mat',
        'external_id' => str($sku)->slug()->toString(),
        'external_parent_sku' => $sku,
        'name' => $sku,
        'slug' => str($sku)->slug()->toString(),
        'description' => $description,
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'external_variant_id' => 'reh4mat-'.$product->external_id.'-default',
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

it('previews size and side variants without writing', function (): void {
    $default = reh4matSizedProduct('ATTACK PRO', REH4MAT_ATTACK_PRO_DESCRIPTION);
    reh4matSizedProduct('BROKEN', '<table><tr><td>Rozmiar</td><td>x</td></tr><tr><td>S M L XL</td><td>1</td></tr></table>');
    reh4matSizedProduct('X-SIZE ONE', '<table><tr><td>Rozmiar</td><td>x</td></tr><tr><td>X-Size</td><td>1</td></tr></table>');

    $this->artisan('reh4mat:sync-size-variants')
        ->expectsOutputToContain('TO_SPLIT=1 (VARIANTS_TO_CREATE=6)')
        ->expectsOutputToContain('SINGLE_OR_UNIVERSAL_SIZE=1')
        ->expectsOutputToContain('NEEDS_REVIEW=1')
        ->expectsOutputToContain('SPLIT product='.$default->product_id.' [active] ATTACK PRO sizes=S,M,L sides=P,L variants=6 price=648.00')
        ->expectsOutputToContain('REVIEW product=')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(ProductVariant::query()->count())->toBe(3);
});

it('creates priced size and side variants, archives the default and rolls back', function (): void {
    $default = reh4matSizedProduct('ATTACK PRO', REH4MAT_ATTACK_PRO_DESCRIPTION);

    $this->artisan('reh4mat:sync-size-variants', ['--write' => true])
        ->expectsOutputToContain('VARIANTS_CREATED=6')
        ->expectsOutputToContain('REH4MAT_SIZE_VARIANTS_WRITE=PASS')
        ->assertSuccessful();

    $variants = ProductVariant::query()
        ->where('product_id', $default->product_id)
        ->where('status', ProductVariantStatus::ACTIVE->value)
        ->with('attributeValues.attribute')
        ->orderBy('id')
        ->get();

    expect($variants)->toHaveCount(6)
        ->and($variants->pluck('sku')->all())->toBe(['ATTACK PRO-S-P', 'ATTACK PRO-S-L', 'ATTACK PRO-M-P', 'ATTACK PRO-M-L', 'ATTACK PRO-L-P', 'ATTACK PRO-L-L'])
        ->and($variants->pluck('price_gross_amount')->unique()->all())->toBe([64800])
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($variants->first()->attributeValues->map(fn ($value) => $value->attribute->name.'='.$value->value)->sort()->values()->all())
        ->toBe(['Rozmiar=S', 'Strona=Prawa'])
        ->and($default->fresh())->status->toBe(ProductVariantStatus::ARCHIVED)
        ->is_default->toBeFalse();

    $this->artisan('reh4mat:sync-size-variants')
        ->expectsOutputToContain('TO_SPLIT=0')
        ->expectsOutputToContain('ALREADY_SPLIT=1')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('reh4mat:sync-size-variants', ['--rollback' => $backup])
        ->expectsOutputToContain('VARIANTS_REMOVED=6')
        ->expectsOutputToContain('VARIANTS_RESTORED=1')
        ->assertSuccessful();

    expect($default->fresh())->status->toBe(ProductVariantStatus::ACTIVE)->is_default->toBeTrue()
        ->and(ProductVariant::query()->where('product_id', $default->product_id)->count())->toBe(1);
});

it('shows a size and side picker in size order once split', function (): void {
    $default = reh4matSizedProduct('ATTACK PRO', REH4MAT_ATTACK_PRO_DESCRIPTION);

    $this->artisan('reh4mat:sync-size-variants', ['--write' => true])->assertSuccessful();

    $payload = $this->get('/products/'.$default->product->slug)->assertOk()->viewData('productPayload');

    $groups = collect($payload['option_groups'])->keyBy('label');
    $sizeValues = collect($groups['Rozmiar']['values'])->sortBy('sort_order')->pluck('label')->all();

    expect($payload['is_purchasable'])->toBeTrue()
        ->and($payload['unavailable_note'])->toBeNull()
        ->and($sizeValues)->toBe(['S', 'M', 'L'])
        ->and(collect($groups['Strona']['values'])->sortBy('sort_order')->pluck('label')->all())->toBe(['Prawa', 'Lewa']);
});

it('blocks buying a sized product that has no size variants yet', function (): void {
    $default = reh4matSizedProduct('ATTACK PRO', REH4MAT_ATTACK_PRO_DESCRIPTION);

    $payload = $this->get('/products/'.$default->product->slug)->assertOk()->viewData('productPayload');

    expect($payload['is_purchasable'])->toBeFalse()
        ->and($payload['unavailable_note'])->toContain('Skontaktuj się z nami');

    $this->withSession(['_token' => 'test-csrf-token'])
        ->post(route('cart.items.store'), ['_token' => 'test-csrf-token', 'product_variant_id' => $default->id, 'quantity' => 1])
        ->assertSessionHasErrors('product_variant_id');

    $this->assertDatabaseCount('cart_items', 0);
});

it('still sells universal-size products as before', function (): void {
    $default = reh4matSizedProduct('X-SIZE ONE', '<table><tr><td>Rozmiar</td><td>x</td></tr><tr><td>X-Size</td><td>1</td></tr></table>');

    expect($this->get('/products/'.$default->product->slug)->viewData('productPayload')['is_purchasable'])->toBeTrue();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartGuestTokenResolver::COOKIE_NAME, (string) str()->uuid())
        ->post(route('cart.items.store'), ['_token' => 'test-csrf-token', 'product_variant_id' => $default->id, 'quantity' => 1])
        ->assertSessionHasNoErrors();
});

it('keeps option variant prices when a re-import carries no price', function (): void {
    $default = reh4matSizedProduct('ATTACK PRO', REH4MAT_ATTACK_PRO_DESCRIPTION);
    $this->artisan('reh4mat:sync-size-variants', ['--write' => true])->assertSuccessful();

    $default->forceFill(['price_gross_amount' => null, 'price_net_amount' => null, 'status' => ProductVariantStatus::ACTIVE])->save();

    app(Reh4MatSizeVariantSync::class)->apply(
        $default->product,
        $default->fresh(),
        Reh4MatSizeOptions::fromDescription(REH4MAT_ATTACK_PRO_DESCRIPTION),
    );

    expect(ProductVariant::query()->where('product_id', $default->product_id)->where('status', ProductVariantStatus::ACTIVE->value)->pluck('price_gross_amount')->unique()->all())
        ->toBe([64800])
        ->and($default->fresh()->status)->toBe(ProductVariantStatus::ARCHIVED);
});

it('prices every size and side variant from the product code in the price list', function (): void {
    $default = reh4matSizedProduct('AM-OSS-04', REH4MAT_ATTACK_PRO_DESCRIPTION, 10000);
    $this->artisan('reh4mat:sync-size-variants', ['--write' => true])->assertSuccessful();

    $this->artisan('reh4mat:apply-price-list', ['--write' => true])
        ->expectsOutputToContain('REH4MAT_PRICE_WRITE=PASS')
        ->assertSuccessful();

    expect(ProductVariant::query()->where('product_id', $default->product_id)->where('status', ProductVariantStatus::ACTIVE->value)->pluck('price_gross_amount')->unique()->all())
        ->toBe([9720]);
});
