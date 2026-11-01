<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute as ProductAttribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/evidence-followup-20261101/DuplicateExport.php';

uses(RefreshDatabase::class);

it('exports every duplicate variant and distinguishes source SKUs from equal option sets without writes', function (): void {
    $ids = [];
    $attribute = ProductAttribute::query()->create(['name' => 'Rozmiar', 'slug' => 'duplicate-review-size', 'display_type' => 'select']);
    $value = AttributeValue::query()->create(['attribute_id' => $attribute->id, 'value' => 'M', 'slug' => 'duplicate-review-m']);
    for ($index = 0; $index < 2; $index++) {
        $p = Product::query()->create(['name' => 'Model ABC', 'slug' => 'duplicate-review-'.$index, 'status' => ProductStatus::ACTIVE, 'seo_title' => 'Model ABC', 'seo_description' => 'Model ABC description.']);
        $v = ProductVariant::query()->create(['product_id' => $p->id, 'sku' => 'REVIEW-'.$index, 'status' => ProductVariantStatus::ACTIVE, 'price_net_amount' => 1000 + $index, 'currency' => Currency::PLN, 'vat_rate' => VatRate::VAT_23, 'stock_status' => StockStatus::IN_STOCK, 'is_default' => true]);
        $v->attributeValues()->attach($value->id);
        $ids[] = $p->id;
    }
    $historical = ProductVariant::query()->create(['product_id' => $ids[0], 'sku' => 'REVIEW-HISTORY', 'status' => ProductVariantStatus::ACTIVE, 'price_net_amount' => 500, 'currency' => Currency::PLN, 'vat_rate' => VatRate::VAT_23, 'stock_status' => StockStatus::OUT_OF_STOCK, 'is_default' => false]);
    $historical->delete();
    Product::query()->create(['name' => 'Unique model', 'slug' => 'duplicate-review-unique', 'status' => ProductStatus::ACTIVE, 'seo_title' => 'Unique model', 'seo_description' => 'Unique model description.']);
    $queries = [];
    DB::listen(function ($q) use (&$queries): void { $queries[] = strtolower(ltrim($q->sql)); });
    $export = (new ProductSeoDuplicateExport)->build();
    foreach ($queries as $query) {
        expect(str_starts_with($query, 'select'))->toBeTrue();
    }
    expect($export['database_writes'])->toBeFalse();
    expect($export['summary'])->toMatchArray(['duplicate_title_groups' => 1, 'exported_products' => 2, 'exported_variants' => 3, 'matching_option_groups' => 1]);
    expect($export['groups'][0]['product_ids'])->toBe($ids);
    expect($export['products'][0]['variants'])->toHaveCount(2);
    expect($export['products'][0]['active_variant_count'])->toBe(1);
    expect($export['products'][0]['variants'][1]['deleted_at'])->not->toBeNull();
    expect($export['groups'][0]['identity_signal'])->toBe('different_active_variant_signatures');
    expect($export['groups'][0]['option_signal'])->toBe('same_active_options_review_candidate');
    expect($export['groups'][0]['action'])->toBe('manual_review_required');
    expect($export['products'][0]['variants'][0]['attributes'][0]['value'])->toBe('M');
    expect($export['products'][0]['variants'][0]['price_net_amount'])->toBe(1000);
});

it('does not treat empty variant sets as matching product identities', function (): void {
    foreach ([1, 2] as $i) {
        Product::query()->create(['name' => 'No variants', 'slug' => 'duplicate-no-variants-'.$i, 'status' => ProductStatus::ACTIVE, 'seo_title' => 'No variants', 'seo_description' => 'Description.']);
    }
    $export = (new ProductSeoDuplicateExport)->build();
    expect($export['groups'][0]['identity_signal'])->toBe('insufficient_active_variant_identity');
    expect($export['groups'][0]['option_signal'])->toBe('options_differ_or_incomplete');
    expect($export['summary']['signature_match_candidate_groups'])->toBe(0);
});
