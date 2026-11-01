<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('does not repeat the product gallery on variants without their own images', function (): void {
    Storage::fake('public');
    $product = Product::query()->create([
        'name' => 'Gallery product',
        'slug' => 'gallery-product',
        'status' => ProductStatus::ACTIVE,
    ]);
    foreach (['A', 'B'] as $i => $suffix) {
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'GALLERY-'.$suffix,
            'status' => ProductVariantStatus::ACTIVE,
            'price_net_amount' => 1000,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_23,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => $i === 0,
        ]);
    }
    foreach ([1, 2, 3] as $sortOrder) {
        ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => 'products/gallery-product/'.$sortOrder.'.webp',
            'sort_order' => $sortOrder,
            'is_main' => $sortOrder === 1,
        ]);
    }

    $payload = $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->viewData('productPayload');

    expect($payload['base_images'])->toHaveCount(3)
        ->and(array_column($payload['variants'], 'images'))->toBe([[], []]);
});
