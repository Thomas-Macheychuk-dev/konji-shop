<?php

use App\Enums\AttributeDisplayType;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductVariant;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('round trips a 2688 variant product through a serialized cache without model graphs', function (): void {
    config()->set('storefront.cache.enabled', true);
    config()->set('storefront.cache.store', 'array');
    config()->set('storefront.cache.product_pages_ttl', 3600);
    config()->set('cache.stores.array.serialize', true);
    Cache::purge('array');

    $product = Product::query()->create([
        'name' => 'Large compression product',
        'slug' => 'large-compression-product',
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
        'short_description' => 'Compression product with many options.',
    ]);
    $attribute = Attribute::query()->create([
        'name' => 'Rozmiar',
        'slug' => 'large-product-size',
        'display_type' => AttributeDisplayType::SELECT,
    ]);
    $value = AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => 'M',
        'slug' => 'large-product-medium',
    ]);
    $image = ProductAttributeValueImage::query()->create([
        'product_id' => $product->id,
        'attribute_value_id' => $value->id,
        'disk' => 'public',
        'path' => 'products/large-product/medium.webp',
        'alt_text' => 'Configured medium image',
        'sort_order' => 0,
        'is_main' => true,
    ]);
    $product->update([
        'default_image_type' => Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE,
        'default_image_id' => $image->id,
    ]);
    $rows = [];
    for ($index = 0; $index < 2688; $index++) {
        $rows[] = [
            'product_id' => $product->id,
            'sku' => 'LARGE-'.$index,
            'status' => ProductVariantStatus::ACTIVE->value,
            'price_net_amount' => 10000,
            'currency' => Currency::PLN->value,
            'vat_rate' => VatRate::VAT_23->value,
            'stock_status' => StockStatus::IN_STOCK->value,
            'is_default' => $index === 0,
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('product_variants')->insert($chunk);
    }
    $variantIds = $product->variants()->orderBy('id')->pluck('id');
    foreach ($variantIds->chunk(100) as $ids) {
        DB::table('product_variant_attribute_value')->insert($ids->map(fn ($id): array => [
            'product_variant_id' => $id,
            'attribute_value_id' => $value->id,
        ])->all());
    }

    $url = 'https://staging.ortezka.pl/products/'.$product->slug;
    $first = $this->get($url)->assertOk();
    $expectedPayload = $first->viewData('productPayload');
    expect($expectedPayload['variants'])->toHaveCount(2688);
    expect($expectedPayload['variants'][2687]['sku'])->toBe('LARGE-2687');
    expect($expectedPayload['variants'][2687]['option_value_ids'])->toBe([$value->id]);
    expect($expectedPayload['variants'][2687]['images'][0]['url'])->toBe($image->url);
    expect($expectedPayload['is_purchasable'])->toBeTrue();

    $cache = app(StorefrontCache::class);
    $key = $cache->versionedKey(StorefrontCache::NAMESPACE_CATALOGUE, 'product-page.v12.https://staging.ortezka.pl.'.$product->id);
    $snapshot = Cache::store('array')->get($key);
    expect($snapshot)->toBeArray();
    $assertPlainData = function ($item) use (&$assertPlainData): void {
        expect(is_object($item))->toBeFalse();
        if (is_array($item)) {
            foreach ($item as $child) {
                $assertPlainData($child);
            }
        }
    };
    $assertPlainData($snapshot);
    // Explicitly simulate the serialization used by Redis/file cache backends.
    Cache::store('array')->put($key, unserialize(serialize($snapshot)), 3600);
    unset($first, $snapshot);

    $variantQueries = 0;
    DB::listen(function ($query) use (&$variantQueries): void {
        if (str_contains($query->sql, 'product_variants')) {
            $variantQueries++;
        }
    });
    $hit = $this->get($url)->assertOk();
    expect($variantQueries)->toBe(0);
    expect($hit->viewData('productPayload'))->toBe($expectedPayload);
    expect($hit->viewData('product')->selectedDefaultImage()->id)->toBe($image->id);
    expect($hit->viewData('defaultVariant')->grossPriceAmount())->toBe(12300);
    expect($hit->viewData('structuredData')[0]['offers']['price'])->toBe('123.00');
    $hit->assertSee('Configured medium image')->assertSee('"sku": "LARGE-0"', false);

    ProductVariant::query()->findOrFail($variantIds->first())->update(['price_net_amount' => 20000]);
    $this->get($url)->assertOk()->assertSee('"price": "246.00"', false);
});
