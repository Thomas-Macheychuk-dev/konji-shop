<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('prices Seni variants at the matopat24 price plus 20% without stacking', function (): void {
    // Seni Super XS 10 szt. costs 19,25 zł on matopat24.
    $variant = seniVariant('24170', 'seni24-24170-26340', 1925, ProductStatus::ACTIVE);
    $other = seniVariant('999999', 'seni24-999999-1', 1000, ProductStatus::ACTIVE);

    $this->artisan('seni:apply-price-markup')
        ->expectsOutputToContain('IN_SHOP=1')
        ->expectsOutputToContain('PRICE_CHANGES=1 ALREADY_CURRENT=0 SKIPPED=0')
        ->expectsOutputToContain('gross 19.25 -> 23.10')
        ->assertSuccessful();

    $this->artisan('seni:apply-price-markup', ['--write' => true])->assertSuccessful();

    expect($variant->fresh()->price_gross_amount)->toBe(2310)
        ->and($variant->fresh()->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(2310))
        ->and($other->fresh()->price_gross_amount)->toBe(1000);

    $this->artisan('seni:apply-price-markup')
        ->expectsOutputToContain('PRICE_CHANGES=0 ALREADY_CURRENT=1')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('seni:apply-price-markup', ['--rollback' => $backup])->assertSuccessful();

    expect($variant->fresh()->price_gross_amount)->toBe(1925);
});

it('publishes only the Seni products added on 15 November', function (): void {
    $category = Category::query()->create(['name' => 'Seni', 'slug' => 'seni', 'status' => 'active']);
    $added = seniVariant('22104', 'seni24-22104-0', 4241, ProductStatus::DRAFT, $category);
    $otherDraft = seniVariant('123456', 'seni24-123456-1', 1000, ProductStatus::DRAFT, $category);

    $this->artisan('seni24:publish-missing', ['--write' => true])
        ->expectsOutputToContain('PRODUCTS_TO_PUBLISH=1')
        ->assertSuccessful();

    expect($added->product->fresh()->status)->toBe(ProductStatus::ACTIVE)
        ->and($added->fresh()->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($otherDraft->product->fresh()->status)->toBe(ProductStatus::DRAFT);
});

function seniVariant(string $productId, string $externalVariantId, int $grossAmount, ProductStatus $status, ?Category $category = null): ProductVariant
{
    $product = Product::query()->create([
        'external_source' => 'seni24',
        'external_id' => $productId,
        'name' => 'Seni '.$productId,
        'slug' => 'seni-'.$productId,
        'status' => $status,
    ]);

    if ($category !== null) {
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'external_variant_id' => $externalVariantId,
        'sku' => 'SENI-'.$productId,
        'status' => $status === ProductStatus::ACTIVE ? ProductVariantStatus::ACTIVE : ProductVariantStatus::DRAFT,
        'price_net_amount' => VatRate::VAT_8->netFromGross($grossAmount),
        'price_gross_amount' => $grossAmount,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ])->load('product');
}
