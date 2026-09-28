<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Products\ProductLegalDisclosures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productForLegalClassificationTest(array $overrides = []): Product
{
    $product = Product::query()->create([
        'name' => 'Produkt prawnie klasyfikowany',
        'slug' => 'produkt-prawnie-klasyfikowany',
        'status' => ProductStatus::ACTIVE,
        ...$overrides,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'LEGAL-PRODUCT-001',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'price_gross_amount' => 12300,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return $product;
}

it('defaults all product legal classification flags to false', function (): void {
    $product = productForLegalClassificationTest();

    expect($product)
        ->has_hygienic_seal->toBeFalse()
        ->is_custom_made->toBeFalse()
        ->show_compression_measurement_notice->toBeFalse();
});

it('shows the approved pre-purchase disclosures for a classified product', function (): void {
    $product = productForLegalClassificationTest([
        'has_hygienic_seal' => true,
        'is_custom_made' => true,
        'show_compression_measurement_notice' => true,
    ]);

    $this
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Dobór rozmiaru')
        ->assertSee(ProductLegalDisclosures::MEASUREMENT_NOTICE)
        ->assertSee('Zabezpieczenie higieniczne')
        ->assertSee(ProductLegalDisclosures::COMPRESSION_HYGIENIC_SEAL_NOTICE)
        ->assertSee('Towar indywidualny')
        ->assertSee(ProductLegalDisclosures::CUSTOM_MADE_NOTICE);
});

it('does not show legal exceptions on an unclassified product', function (): void {
    $product = productForLegalClassificationTest();

    $this
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertDontSee(ProductLegalDisclosures::MEASUREMENT_NOTICE)
        ->assertDontSee(ProductLegalDisclosures::HYGIENIC_SEAL_NOTICE)
        ->assertDontSee(ProductLegalDisclosures::COMPRESSION_HYGIENIC_SEAL_NOTICE)
        ->assertDontSee(ProductLegalDisclosures::CUSTOM_MADE_NOTICE);
});
