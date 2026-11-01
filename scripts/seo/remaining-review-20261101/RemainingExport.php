<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/** Complete catalogue rows for sixteen reviewed products; commercial references are counts only. */
final class ProductSeoRemainingIdentityExport
{
    public function __construct(private readonly array $review) {}

    private function rows(string $table, string $column, array $ids, array $order): array
    {
        $query = DB::table($table)->whereIn($column, $ids);
        foreach ($order as $field) {
            $query->orderBy($field);
        }

        return array_map(static fn ($row): array => (array) $row, $query->get()->all());
    }

    public function build(): array
    {
        if (($this->review['version'] ?? null) !== 'product-seo-remaining-identities-20261101-v1'
            || count($this->review['groups'] ?? []) !== 8) {
            throw new RuntimeException('Unexpected preservation review.');
        }
        $expected = [];
        foreach ($this->review['groups'] as $group) {
            if (count($group['products']) !== 2) {
                throw new RuntimeException('Expected eight disjoint pairs.');
            }
            foreach ($group['products'] as $product) {
                $expected[$product['id']] = $product;
            }
        }
        if (count($expected) !== 16) {
            throw new RuntimeException('Expected sixteen unique reviewed products.');
        }
        $ids = array_keys($expected);
        sort($ids);
        if ($ids !== [8869, 8870, 9309, 9332, 10479, 10523, 11039, 11040, 12021, 12022, 12503, 12562, 12588, 12589, 12624, 12625]) {
            throw new RuntimeException('Unexpected remaining identity scope.');
        }
        // Explicit product fields avoid exporting arbitrary importer payloads.
        $products = DB::table('products')->whereIn('id', $ids)->orderBy('id')->get([
            'id', 'name', 'slug', 'external_source', 'external_id', 'status', 'deleted_at',
            'description', 'short_description', 'seo_title', 'seo_description', 'published_at',
            'default_image_type', 'default_image_id', 'created_at', 'updated_at',
        ])->map(static fn ($row): array => (array) $row)->all();
        if (count($products) !== 16) {
            throw new RuntimeException('A reviewed product is missing.');
        }
        foreach ($products as $product) {
            foreach ($expected[$product['id']] as $field => $value) {
                if ($product[$field] !== $value) {
                    throw new RuntimeException('Reviewed identity changed: '.$product['id'].' '.$field);
                }
            }
            if ($product['status'] !== 'active' || $product['deleted_at'] !== null) {
                throw new RuntimeException('Reviewed product is no longer active: '.$product['id']);
            }
        }
        $rows = [];
        foreach ([
            'product_variants' => ['id'],
            'category_product' => ['product_id', 'category_id'],
            'product_attribute_value' => ['product_id', 'attribute_value_id'],
            'product_images' => ['product_id', 'sort_order', 'id'],
            'product_attribute_value_images' => ['product_id', 'attribute_value_id', 'sort_order', 'id'],
            'product_documents' => ['product_id', 'id'],
        ] as $table => $order) {
            $rows[$table] = $this->rows($table, 'product_id', $ids, $order);
        }
        $variantIds = array_column($rows['product_variants'], 'id');
        $rows['product_variant_attribute_value'] = $this->rows('product_variant_attribute_value', 'product_variant_id', $variantIds, ['product_variant_id', 'attribute_value_id']);
        $valueIds = array_values(array_unique(array_merge(
            array_column($rows['product_attribute_value'], 'attribute_value_id'),
            array_column($rows['product_variant_attribute_value'], 'attribute_value_id'),
            array_column($rows['product_attribute_value_images'], 'attribute_value_id'),
        )));
        $rows['attribute_values'] = $this->rows('attribute_values', 'id', $valueIds, ['id']);
        $rows['attributes'] = $this->rows('attributes', 'id', array_unique(array_column($rows['attribute_values'], 'attribute_id')), ['id']);
        $rows['categories'] = $this->rows('categories', 'id', array_unique(array_column($rows['category_product'], 'category_id')), ['id']);
        $referenceInput = array_map(static fn ($product): array => [
            'id' => $product['id'],
            'variants' => array_values(array_filter($rows['product_variants'], static fn ($variant): bool => $variant['product_id'] === $product['id'])),
        ], $products);
        $references = (new ProductSeoReferenceAudit)->build($referenceInput);
        $counts = [];
        foreach ($products as $product) {
            $count = ['product_id' => $product['id']];
            foreach (['product_images', 'product_attribute_value_images', 'product_documents', 'product_variants', 'category_product'] as $table) {
                $count[$table] = count(array_filter($rows[$table], static fn ($row): bool => $row['product_id'] === $product['id']));
            }
            $counts[] = $count;
        }

        return [
            'version' => $this->review['version'],
            'exported_at' => now()->toIso8601String(),
            'database_writes' => false,
            'review' => $this->review,
            'products' => $products,
            'catalogue_rows' => $rows,
            ...$references,
            'summary' => [
                'reviewed_groups' => 8,
                'exported_products' => count($products),
                'exported_images' => count($rows['product_images']),
                'exported_attribute_images' => count($rows['product_attribute_value_images']),
                'exported_documents' => count($rows['product_documents']),
                'exported_variants' => count($rows['product_variants']),
                'reference_tables_scanned' => count(array_unique(array_column($references['reference_inventory'], 'table'))),
                'per_product' => $counts,
            ],
            'limitations' => [
                'Catalogue text and media rows are complete, including soft-deleted variants. No truncation or sampling.',
                'Media paths and stored checksums are exported; file existence/content is not independently verified.',
                'Commercial/customer/cart records and arbitrary payloads are never exported. References are counts only.',
                'This export does not choose survivors, declare merge eligibility or modify the catalogue.',
            ],
        ];
    }
}
