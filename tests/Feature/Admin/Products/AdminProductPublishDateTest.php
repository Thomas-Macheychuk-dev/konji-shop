<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProductForPublishDateTest(ProductStatus $status, ?CarbonInterface $publishedAt = null, string $slug = 'publish-date-product'): Product
{
    return Product::query()->create([
        'name' => 'Publish Date Product',
        'slug' => $slug,
        'status' => $status,
        'published_at' => $publishedAt,
    ]);
}

function updateProductStatusForPublishDateTest(Product $product, ProductStatus $status): void
{
    test()
        ->actingAs(User::factory()->create(['is_admin' => true]))
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => $status->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
}

it('publishes a product when an admin activates it', function (): void {
    $this->freezeSecond();
    $product = createProductForPublishDateTest(ProductStatus::DRAFT);

    updateProductStatusForPublishDateTest($product, ProductStatus::ACTIVE);

    expect($product->refresh()->published_at?->equalTo(now()))->toBeTrue();
});

it('publishes an active product that has no publish date when an admin saves it', function (): void {
    $this->freezeSecond();
    $product = createProductForPublishDateTest(ProductStatus::ACTIVE);

    updateProductStatusForPublishDateTest($product, ProductStatus::ACTIVE);

    expect($product->refresh()->published_at?->equalTo(now()))->toBeTrue();
});

it('keeps an existing publish date when an admin saves an active product', function (): void {
    $publishedAt = now()->subMonth()->startOfSecond();
    $product = createProductForPublishDateTest(ProductStatus::ACTIVE, $publishedAt);

    updateProductStatusForPublishDateTest($product, ProductStatus::ACTIVE);

    expect($product->refresh()->published_at?->equalTo($publishedAt))->toBeTrue();
});

it('does not publish a product an admin keeps as a draft', function (): void {
    $product = createProductForPublishDateTest(ProductStatus::DRAFT);

    updateProductStatusForPublishDateTest($product, ProductStatus::DRAFT);

    expect($product->refresh()->published_at)->toBeNull();
});

it('publishes a product an admin creates as active', function (ProductStatus $status, bool $published): void {
    $this
        ->actingAs(User::factory()->create(['is_admin' => true]))
        ->post(route('admin.products.store'), [
            'name' => 'Nowy produkt '.$status->value,
            'status' => $status->value,
            'default_variant_index' => 0,
            'variants' => [[
                'sku' => 'NEW-'.$status->value,
                'status' => ProductVariantStatus::ACTIVE->value,
                'gross_price' => '45.00',
                'currency' => Currency::PLN->value,
                'vat_rate' => VatRate::VAT_8->value,
                'stock_status' => StockStatus::IN_STOCK->value,
                'package_weight_grams' => 500,
                'package_length_mm' => 300,
                'package_width_mm' => 200,
                'package_height_mm' => 100,
                'attributes' => [],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $product = Product::query()->where('name', 'Nowy produkt '.$status->value)->firstOrFail();

    expect($product->published_at !== null)->toBe($published);
})->with([
    'active' => [ProductStatus::ACTIVE, true],
    'draft' => [ProductStatus::DRAFT, false],
]);

it('previews active products without a publish date and changes nothing', function (): void {
    $product = createProductForPublishDateTest(ProductStatus::ACTIVE);

    $this->artisan('products:backfill-publish-dates')
        ->expectsOutput('TO_UPDATE=1')
        ->expectsOutput('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($product->refresh()->published_at)->toBeNull();
});

it('backfills the publish date of active products from their creation date', function (): void {
    $this->freezeSecond();
    $missing = createProductForPublishDateTest(ProductStatus::ACTIVE, null, 'missing');
    $missing->forceFill(['created_at' => now()->subYear()->startOfSecond()])->saveQuietly();
    $published = createProductForPublishDateTest(ProductStatus::ACTIVE, now()->subDay()->startOfSecond(), 'published');
    $draft = createProductForPublishDateTest(ProductStatus::DRAFT, null, 'draft');

    $this->artisan('products:backfill-publish-dates', ['--write' => true])
        ->expectsOutput('UPDATED=1')
        ->expectsOutput('PRODUCT_PUBLISH_DATES_WRITE=PASS')
        ->assertSuccessful();

    expect($missing->refresh()->published_at?->equalTo($missing->created_at))->toBeTrue()
        ->and($published->refresh()->published_at?->equalTo(now()->subDay()->startOfSecond()))->toBeTrue()
        ->and($draft->refresh()->published_at)->toBeNull();
});

it('makes a backfilled product purchasable on its product page', function (): void {
    $product = createProductForPublishDateTest(ProductStatus::ACTIVE);

    $this->artisan('products:backfill-publish-dates', ['--write' => true])->assertSuccessful();

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertViewHas('productPayload.is_purchasable', true);
});
