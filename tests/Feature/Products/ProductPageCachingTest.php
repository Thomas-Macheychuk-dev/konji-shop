<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('storefront.cache.enabled', true);
    Config::set('storefront.cache.store', 'array');
    Config::set('storefront.cache.product_pages_ttl', 3600);

    Cache::store('array')->flush();
});

it('caches active product page data and invalidates it after a product mutation', function (): void {
    $product = Product::query()->create([
        'name' => 'Cached Knee Brace',
        'slug' => 'cached-knee-brace',
        'short_description' => 'Cacheable public product.',
        'description' => '<p>Original cached description.</p>',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'CACHE-BRACE-001',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => VatRate::VAT_23->netFromGross(12300),
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Cached Knee Brace')
        ->assertSee('Original cached description', false);

    Product::withoutTimestamps(function () use ($product): void {
        $product->update([
            'name' => 'Changed Product',
            'description' => '<p>Changed product description.</p>',
        ]);
    });

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Changed Product')
        ->assertSee('Changed product description', false)
        ->assertDontSee('Cached Knee Brace')
        ->assertDontSee('Original cached description', false);
});
