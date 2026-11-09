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

const STABILO_BASE_ID = '7f2fd08e0fe530ef4073ce128a090ff5d623ee3b6c89c53a10364560132165c0';

it('previews the Timago size variants without writing', function (): void {
    $spiky = singleVariantProduct('timago', '514', 'SPIKY - Piłka rehabilitacyjna z kolcami (jeżyk)', 'SPIKY', null);

    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'timago-2026'])
        ->expectsOutputToContain('TABLE_PRODUCTS=4 TO_SPLIT=1 NEW_VARIANTS=3 ALREADY_CURRENT=0 SKIPPED=0 PRODUCT_MISSING=3')
        ->expectsOutputToContain('SPLIT product='.$spiky->product_id.' [active] SPIKY - Piłka rehabilitacyjna z kolcami (jeżyk) by Rozmiar')
        ->expectsOutputToContain('KEEP variant='.$spiky->id.' 5 cm (czerwona) [Rehabilitacja:168] gross (empty) -> 6.40 (net 5.93, VAT 8%)')
        ->expectsOutputToContain('NEW 8 cm (zielona) [Rehabilitacja:171] gross new -> 8.78 (net 8.13, VAT 8%)')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(ProductVariant::query()->where('product_id', $spiky->product_id)->count())->toBe(1);
});

it('splits a Vermeiren product into size variants, keeps a backup and rolls back', function (): void {
    $default = singleVariantProduct('vermeiren', STABILO_BASE_ID, 'STABILO BASE', 'STABILO BASE', 120000);

    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'vermeiren-2026', '--write' => true])
        ->expectsOutputToContain('SPLIT_PRODUCTS=1 CREATED_VARIANTS=4')
        ->expectsOutputToContain('SUPPLIER_VARIANT_WRITE=PASS')
        ->assertSuccessful();

    $variants = ProductVariant::query()->where('product_id', $default->product_id)->with('attributeValues.attribute')->orderBy('id')->get();

    expect($variants)->toHaveCount(5)
        ->and($variants->first()->id)->toBe($default->id)
        ->and($variants->first()->sku)->toBe('STABILO BASE')
        ->and($variants->first()->is_default)->toBeTrue()
        ->and($variants->map(fn (ProductVariant $v) => $v->attributeValues->sole()->value)->all())->toBe(['S', 'S+', 'M', 'M+', 'L'])
        ->and($variants->map(fn (ProductVariant $v) => $v->price_gross_amount)->all())->toBe([107800, 126100, 137300, 150200, 157900])
        ->and($variants->first()->attributeValues->sole()->attribute->name)->toBe('Rozmiar')
        ->and($variants[3]->sku)->toBe('STABILO BASE-M-PLUS')
        ->and($variants[3]->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($variants[3]->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and(Product::query()->find($default->product_id)->attributeValues)->toHaveCount(5);

    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'vermeiren-2026'])
        ->expectsOutputToContain('TO_SPLIT=0 NEW_VARIANTS=0 ALREADY_CURRENT=1')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:apply-supplier-variants', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRODUCTS=1 OF 1')
        ->assertSuccessful();

    $restored = ProductVariant::query()->where('product_id', $default->product_id)->get();

    expect($restored)->toHaveCount(1)
        ->and($restored->sole()->price_gross_amount)->toBe(120000)
        ->and($restored->sole()->attributeValues)->toBeEmpty()
        ->and(Product::query()->find($default->product_id)->attributeValues)->toBeEmpty();

    // A second split after the rollback must not collide with the soft-deleted SKUs.
    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'vermeiren-2026', '--write' => true])
        ->expectsOutputToContain('CREATED_VARIANTS=4')
        ->assertSuccessful();
});

it('shows the variants as a size picker in size order on the product page', function (): void {
    $default = singleVariantProduct('vermeiren', STABILO_BASE_ID, 'STABILO BASE', 'STABILO BASE', null);

    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'vermeiren-2026', '--write' => true])->assertSuccessful();

    $payload = $this->get('/products/'.$default->product->slug)->assertOk()->viewData('productPayload');
    $sizes = collect(collect($payload['option_groups'])->keyBy('label')['Rozmiar']['values'])->sortBy('sort_order')->pluck('label')->all();

    expect($payload['is_purchasable'])->toBeTrue()
        ->and($sizes)->toBe(['S', 'S+', 'M', 'M+', 'L'])
        ->and($payload['variants'])->toHaveCount(5);
});

it('leaves products that already have other variants alone', function (): void {
    $default = singleVariantProduct('vermeiren', STABILO_BASE_ID, 'STABILO BASE', 'STABILO BASE', 120000);
    ProductVariant::query()->create([
        'product_id' => $default->product_id,
        'sku' => 'STABILO-OTHER',
        'status' => ProductVariantStatus::ACTIVE,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
    ]);

    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'vermeiren-2026', '--write' => true])
        ->expectsOutputToContain('SKIPPED product='.$default->product_id.' [active] STABILO BASE reason=has 2 variants that differ from the table')
        ->expectsOutputToContain('NOTHING_TO_WRITE')
        ->assertSuccessful();
});

it('rejects an unknown variant table', function (): void {
    $this->artisan('catalogue:apply-supplier-variants', ['list' => 'nope'])
        ->expectsOutputToContain('VARIANT_LIST_ERROR=Choose one of: timago-2026, vermeiren-2026')
        ->assertFailed();
});

function singleVariantProduct(string $source, string $externalId, string $name, string $sku, ?int $grossAmount): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => $source,
        'external_id' => $externalId,
        'name' => $name,
        'slug' => str($source.'-'.$name)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'external_variant_id' => $source.'-'.$externalId.'-default',
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
