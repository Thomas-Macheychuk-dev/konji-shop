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
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps Wojdak base colour and inset colour as separate storefront option groups', function (): void {
    $product = Product::query()->create([
        'name' => 'Bluza E2002 Wojdak',
        'slug' => 'bluza-e2002-wojdak',
        'status' => ProductStatus::ACTIVE,
    ]);

    $colour = Attribute::query()->create([
        'external_attribute_id' => 'wojdak-kolory',
        'name' => 'Kolor',
        'slug' => 'kolor',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $insetColour = Attribute::query()->create([
        'external_attribute_id' => 'wojdak-kolor_wstawek',
        'name' => 'Kolor wstawek',
        'slug' => 'kolor-wstawek',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $size = Attribute::query()->create([
        'name' => 'Rozmiar damski',
        'slug' => 'rozmiar-damski',
        'display_type' => AttributeDisplayType::SELECT,
    ]);

    $colour196 = AttributeValue::query()->create([
        'attribute_id' => $colour->id,
        'external_option_id' => 'wojdak-kolory-196',
        'value' => '196',
        'slug' => '196',
        'swatch_type' => 'color',
        'swatch_value' => '#2e619c',
    ]);

    $inset196 = AttributeValue::query()->create([
        'attribute_id' => $insetColour->id,
        'external_option_id' => 'wojdak-kolor_wstawek-196',
        'value' => '196',
        'slug' => '196',
        'swatch_type' => 'color',
        'swatch_value' => '#2e619c',
    ]);

    $size34 = AttributeValue::query()->create([
        'attribute_id' => $size->id,
        'value' => '34',
        'slug' => '34',
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'M2002011919060343',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10650,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $variant->attributeValues()->sync([$colour196->id, $inset196->id, $size34->id]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('"code":"color"', false)
        ->assertSee('"label":"Kolor"', false)
        ->assertSee('"code":"kolor_wstawek"', false)
        ->assertSee('"label":"Kolor wstawek"', false)
        ->assertSee('"type":"color"', false)
        ->assertSee('"value":"#2e619c"', false);
});
