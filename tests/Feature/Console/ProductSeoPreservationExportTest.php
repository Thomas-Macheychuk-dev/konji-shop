<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/scripts/seo/variant-review-20261101/ReferenceAudit.php';
require_once dirname(__DIR__, 3).'/scripts/seo/consolidation-followup-20261101/PreservationExport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->review = json_decode(file_get_contents(base_path('scripts/seo/consolidation-followup-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($this->review['groups'] as $group) {
        foreach ($group['products'] as $row) {
            Product::query()->forceCreate($row + [
                'status' => ProductStatus::ACTIVE,
                'seo_title' => $group['label'],
                'seo_description' => 'Complete description.',
                'description' => '<p>'.str_repeat('Complete catalogue text. ', 1200).'</p>',
                'short_description' => '<p>Complete short text.</p>',
            ]);
        }
    }
    $this->productId = $this->review['groups'][0]['products'][0]['id'];
});

it('exports every image document variant and full HTML without writes or unrelated commercial payloads', function (): void {
    $id = $this->productId;
    for ($i = 0; $i < 8; $i++) {
        DB::table('product_images')->insert([
            'product_id' => $id, 'disk' => 'public', 'path' => 'preservation/'.$i.'.jpg',
            'source_url' => 'https://example.com/gallery/'.$i.'.jpg', 'sort_order' => $i,
            'sha256' => hash('sha256', 'image-'.$i), 'alt_text' => 'Photo '.$i,
        ]);
    }
    DB::table('attributes')->insert(['id' => 990, 'name' => 'Colour', 'slug' => 'preservation-colour', 'display_type' => 'select']);
    DB::table('attribute_values')->insert(['id' => 991, 'attribute_id' => 990, 'value' => 'Grey', 'slug' => 'preservation-grey']);
    DB::table('product_attribute_value')->insert(['product_id' => $id, 'attribute_value_id' => 991]);
    DB::table('product_attribute_value_images')->insert(['product_id' => $id, 'attribute_value_id' => 991, 'disk' => 'public', 'path' => 'preservation/colour.jpg']);
    DB::table('product_documents')->insert([
        'product_id' => $id, 'external_source' => 'review', 'external_id' => 'manual-1',
        'title' => 'Full manual', 'file_name' => 'manual.pdf', 'disk' => 'local',
        'path' => 'private/catalogue/manual.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 123, 'sha256' => hash('sha256', 'manual'),
    ]);
    foreach ([null, '2026-10-01 00:00:00'] as $i => $deletedAt) {
        DB::table('product_variants')->insert([
            'id' => 9990 + $i, 'product_id' => $id, 'sku' => 'PRESERVATION-'.$i,
            'status' => 'draft', 'currency' => 'PLN', 'vat_rate' => 8,
            'stock_status' => 'in_stock', 'is_default' => $i === 0, 'deleted_at' => $deletedAt,
        ]);
    }
    DB::table('product_variant_attribute_value')->insert(['product_variant_id' => 9990, 'attribute_value_id' => 991]);
    $order = \App\Models\Order::factory()->create();
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'product_id' => $id, 'product_variant_id' => 9990,
        'product_name_snapshot' => 'DO NOT EXPORT THIS COMMERCIAL PAYLOAD',
        'unit_price_amount' => 100, 'quantity' => 1, 'line_total_amount' => 100,
    ]);
    Product::query()->forceCreate(['name' => 'Unrelated catalogue item', 'slug' => 'unrelated-preservation', 'status' => ProductStatus::ACTIVE]);
    $before = DB::table('products')->orderBy('id')->get()->toJson();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void { $queries[] = strtolower(ltrim($query->sql)); });
    $export = (new ProductSeoPreservationExport($this->review))->build();
    foreach ($queries as $query) {
        expect(preg_match('/^(select|pragma)\b/', $query))->toBe(1);
    }
    expect($export['database_writes'])->toBeFalse();
    expect($export['summary'])->toMatchArray(['reviewed_groups' => 5, 'exported_products' => 10, 'exported_images' => 8, 'exported_attribute_images' => 1, 'exported_documents' => 1, 'exported_variants' => 2]);
    expect($export['products'][0]['description'])->toBe(Product::findOrFail($id)->description);
    expect(strlen($export['products'][0]['description']))->toBeGreaterThan(20000);
    expect($export['catalogue_rows']['product_images'][7]['source_url'])->toBe('https://example.com/gallery/7.jpg');
    expect($export['catalogue_rows']['product_documents'][0]['sha256'])->toBe(hash('sha256', 'manual'));
    expect($export['catalogue_rows']['product_variants'][1]['deleted_at'])->not->toBeNull();
    expect($export['catalogue_rows']['product_variant_attribute_value'])->toHaveCount(1);
    expect($export['catalogue_rows']['attributes'][0]['name'])->toBe('Colour');
    expect($export['references'][0]['references']['order_items.product_id'])->toBe(1);
    expect(json_encode($export))->not->toContain('DO NOT EXPORT THIS COMMERCIAL PAYLOAD', 'Unrelated catalogue item');
    expect(DB::table('products')->orderBy('id')->get()->toJson())->toBe($before);
});

it('refuses missing archived or changed source identities', function (string $change): void {
    $id = $this->productId;
    if ($change === 'missing') {
        DB::table('products')->where('id', $id)->delete();
    } else {
        DB::table('products')->where('id', $id)->update(match ($change) {
            'archived' => ['status' => 'archived'],
            'deleted' => ['deleted_at' => now()],
            'source' => ['external_id' => 'another-source-product'],
            'slug' => ['slug' => 'changed-preservation-slug'],
        });
    }
    expect(fn () => (new ProductSeoPreservationExport($this->review))->build())->toThrow(RuntimeException::class);
})->with(['missing', 'archived', 'deleted', 'source', 'slug']);

it('rejects an incomplete or overlapping review scope', function (): void {
    $this->review['groups'][1] = $this->review['groups'][0];
    expect(fn () => (new ProductSeoPreservationExport($this->review))->build())->toThrow(RuntimeException::class, 'ten unique');
});
