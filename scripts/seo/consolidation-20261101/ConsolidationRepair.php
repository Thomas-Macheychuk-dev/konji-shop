<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSeoConsolidationRepair
{
    private array $ids;

    public function __construct(private readonly array $manifest)
    {
        if (($manifest['batch'] ?? '') !== 'product-seo-consolidation-20261101-v1' || count($manifest['pairs'] ?? []) !== 6) {
            throw new RuntimeException('Unexpected consolidation manifest.');
        }
        $this->ids = [];
        foreach ($manifest['pairs'] as $pair) {
            $this->ids[] = $pair['target']['id'];
            $this->ids[] = $pair['source']['id'];
        }
        if (count(array_unique($this->ids)) !== count($this->ids)) {
            throw new RuntimeException('Overlapping consolidation pairs.');
        }
        sort($this->ids);
    }

    private function rows(string $table, string $column, array $ids, array $order, bool $lock): array
    {
        $query = DB::table($table)->whereIn($column, $ids);
        foreach ($order as $field) {
            $query->orderBy($field);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    private function snapshot(bool $lock = false): array
    {
        $state = ['products' => $this->rows('products', 'id', $this->ids, ['id'], $lock)];
        $state['product_variants'] = $this->rows('product_variants', 'product_id', $this->ids, ['id'], $lock);
        foreach (['category_product' => ['product_id', 'category_id'], 'product_attribute_value' => ['product_id', 'attribute_value_id'], 'product_images' => ['id'], 'product_attribute_value_images' => ['id'], 'product_documents' => ['id']] as $table => $order) {
            $state[$table] = $this->rows($table, 'product_id', $this->ids, $order, $lock);
        }
        $variantIds = array_column($state['product_variants'], 'id');
        $state['product_variant_attribute_value'] = $this->rows('product_variant_attribute_value', 'product_variant_id', $variantIds, ['product_variant_id', 'attribute_value_id'], $lock);
        $valueIds = array_unique(array_merge(array_column($state['product_attribute_value'], 'attribute_value_id'), array_column($state['product_variant_attribute_value'], 'attribute_value_id')));
        $state['attribute_values'] = $this->rows('attribute_values', 'id', $valueIds, ['id'], $lock);
        $state['attributes'] = $this->rows('attributes', 'id', array_unique(array_column($state['attribute_values'], 'attribute_id')), ['id'], $lock);
        $state['categories'] = $this->rows('categories', 'id', array_unique(array_column($state['category_product'], 'category_id')), ['id'], $lock);
        $products = [];
        foreach ($state['products'] as $p) {
            $products[] = ['id' => $p['id'], 'variants' => array_values(array_filter($state['product_variants'], fn ($v): bool => $v['product_id'] === $p['id']))];
        }
        $audit = (new ProductSeoReferenceAudit)->build($products);
        foreach ($audit['reference_limitations'] as $limitation) {
            if (str_starts_with($limitation, 'Unresolved')) {
                throw new RuntimeException('Unresolved reference schema blocks consolidation.');
            }
        }
        $inventory = static function (array $items): array {
            $keys = array_map(fn ($i): string => $i['table'].'.'.$i['column'].':'.$i['target'], $items);
            sort($keys);

            return $keys;
        };
        if ($inventory($audit['reference_inventory']) !== $inventory($this->manifest['reference_inventory'])) {
            throw new RuntimeException('Reference schema changed; review the new references first.');
        }
        foreach ($audit['reference_inventory'] as $reference) {
            $ids = $reference['target'] === 'products' ? $this->ids : $variantIds;
            $query = DB::table($reference['table'])->whereIn($reference['column'], $ids);
            if ($reference['morph'] !== null) {
                $query->whereIn($reference['morph']['column'], $reference['morph']['types']);
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            // Read only keys, never commercial/customer payloads, even to lock rows.
            $count = $query->get([$reference['column']])->count();
            if ($count > 0 && ! in_array($reference['table'], array_keys($state), true)) {
                throw new RuntimeException('Commercial or unreviewed reference blocks consolidation: '.$reference['table']);
            }
        }
        $state['references'] = $audit['references'];

        return $state;
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function prove(array $state): void
    {
        $products = array_column($state['products'], null, 'id');
        $references = array_column($state['references'], null, 'product_id');
        $valueRows = array_column($state['attribute_values'], null, 'id');
        $attributeRows = array_column($state['attributes'], null, 'id');
        foreach ($this->manifest['pairs'] as $pair) {
            foreach (['target', 'source'] as $role) {
                $expected = $pair[$role];
                $id = $expected['id'];
                $row = $products[$id] ?? throw new RuntimeException('Missing reviewed product.');
                foreach (['name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description'] as $field) {
                    if ($row[$field] !== $expected[$field]) {
                        throw new RuntimeException('Identity or SEO changed: '.$id.' '.$field);
                    }
                }
                if ($row['status'] !== 'active' || $row['deleted_at'] !== null || $row['default_image_id'] !== null) {
                    throw new RuntimeException('Product eligibility changed: '.$id);
                }
                foreach (['description', 'short_description'] as $field) {
                    if (! hash_equals($expected[$field.'_sha256'], hash('sha256', ProductSeoMetadata::plain($row[$field])))) {
                        throw new RuntimeException('Description evidence changed: '.$id);
                    }
                }
                $variants = array_values(array_filter($state['product_variants'], fn ($v): bool => $v['product_id'] === $id));
                if (count($variants) !== 1 || $variants[0]['sku'] !== $expected['variant_sku'] || $variants[0]['status'] !== 'draft' || $variants[0]['deleted_at'] !== null || $variants[0]['price_net_amount'] !== null || $variants[0]['price_gross_amount'] !== null) {
                    throw new RuntimeException('Variant configuration changed: '.$id);
                }
                $values = array_column(array_filter($state['product_attribute_value'], fn ($r): bool => $r['product_id'] === $id), 'attribute_value_id');
                $expectedValues = $expected['attribute_value_ids'];
                sort($values);
                sort($expectedValues);
                if ($values !== $expectedValues) {
                    throw new RuntimeException('Product attributes changed: '.$id);
                }
                foreach ($expected['attribute_evidence'] as $evidence) {
                    $value = $valueRows[$evidence['value_id']];
                    if (ProductSeoMetadata::plain($value['value']) !== $evidence['value'] || $attributeRows[$value['attribute_id']]['name'] !== $evidence['attribute']) {
                        throw new RuntimeException('Attribute evidence changed: '.$id);
                    }
                }
                $categories = array_map(fn ($r): array => ['category_id' => $r['category_id'], 'is_primary' => (bool) $r['is_primary']], array_values(array_filter($state['category_product'], fn ($r): bool => $r['product_id'] === $id)));
                if ($categories !== $expected['categories'] || $references[$id]['references'] !== $expected['references']) {
                    throw new RuntimeException('Categories or reference counts changed: '.$id);
                }
            }
            foreach (['product_images', 'product_attribute_value_images'] as $table) {
                $urls = [];
                foreach (['target', 'source'] as $role) {
                    $urls[$role] = [];
                    foreach ($state[$table] as $image) {
                        if ($image['product_id'] !== $pair[$role]['id']) {
                            continue;
                        }
                        if (empty($image['source_url'])) {
                            throw new RuntimeException('Missing manufacturer image identity.');
                        }
                        $urls[$role][] = trim($image['source_url']);
                    }
                    sort($urls[$role]);
                }
                if ($urls['target'] !== $urls['source'] || ($table === 'product_images' && $urls['target'] === [])) {
                    throw new RuntimeException('Manufacturer image sets differ.');
                }
                if ($table === 'product_images' && array_diff($pair['manufacturer_image_samples'], $urls['target']) !== []) {
                    throw new RuntimeException('Reviewed manufacturer images are missing.');
                }
            }
        }
        foreach ($state['categories'] as $category) {
            if ($category['status'] !== 'active' || $category['deleted_at'] !== null) {
                throw new RuntimeException('Category eligibility changed.');
            }
        }
    }

    public function plan(): array
    {
        $state = $this->snapshot();
        $this->prove($state);
        $timestamp = now()->format('Y-m-d H:i:s');
        $additions = [];
        foreach ($this->manifest['pairs'] as $pair) {
            $existing = array_column($pair['target']['categories'], 'category_id');
            foreach ($pair['source']['categories'] as $category) {
                if (! in_array($category['category_id'], $existing, true)) {
                    $additions[] = ['product_id' => $pair['target']['id'], 'category_id' => $category['category_id'], 'is_primary' => 0, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
            }
        }

        $titles = [];
        $sourceIds = array_column(array_column($this->manifest['pairs'], 'source'), 'id');
        foreach (DB::table('products')->where('status', 'active')->whereNull('deleted_at')->get(['id', 'seo_title']) as $row) {
            if (! in_array($row->id, $sourceIds, true)) {
                $titles[mb_strtolower(ProductSeoMetadata::plain($row->seo_title))][] = $row->id;
            }
        }

        return ['operations' => array_map(fn ($p): array => ['source_id' => $p['source']['id'], 'source_slug' => $p['source']['slug'], 'target_id' => $p['target']['id'], 'target_slug' => $p['target']['slug'], 'after_status' => 'archived'], $this->manifest['pairs']), 'reasons' => ['reviewed_duplicate_consolidation' => 6], 'duplicate_title_product_ids' => array_values(array_filter($titles, fn ($ids): bool => count($ids) > 1)), 'batch' => $this->manifest['batch'], 'manifest_sha256' => self::hash($this->manifest), 'before' => $state, 'before_sha256' => self::hash($state), 'timestamp' => $timestamp, 'category_additions' => $additions, 'stats' => ['active_products' => DB::table('products')->where('status', 'active')->whereNull('deleted_at')->count(), 'changed_products' => 6, 'archive_products' => 6, 'new_category_links' => count($additions), 'deleted_rows' => 0, 'changed_variants' => 0]];
    }

    private function after(array $plan): array
    {
        $state = $plan['before'];
        $sources = array_column(array_column($this->manifest['pairs'], 'source'), 'id');
        foreach ($state['products'] as &$product) {
            if (in_array($product['id'], $sources, true)) {
                $product['status'] = 'archived';
                $product['updated_at'] = $plan['timestamp'];
            }
        }
        unset($product);
        $state['category_product'] = array_merge($state['category_product'], $plan['category_additions']);
        usort($state['category_product'], fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']]);
        foreach ($state['references'] as &$reference) {
            $reference['references']['category_product.product_id'] += count(array_filter($plan['category_additions'], fn ($r): bool => $r['product_id'] === $reference['product_id']));
        }
        unset($reference);

        return $state;
    }

    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->validatePlan($plan);
            $state = $this->snapshot(true);
            $this->prove($state);
            if (! hash_equals($plan['before_sha256'], self::hash($state))) {
                throw new RuntimeException('Live snapshot changed; no writes applied.');
            }
            foreach ($this->manifest['pairs'] as $pair) {
                DB::table('products')->where('id', $pair['source']['id'])->update(['status' => 'archived', 'updated_at' => $plan['timestamp']]);
            }
            DB::table('category_product')->insert($plan['category_additions']);
            $this->verify($plan);
        });
    }

    private function validatePlan(array $plan): void
    {
        if (($plan['batch'] ?? '') !== $this->manifest['batch'] || ! hash_equals($plan['manifest_sha256'], self::hash($this->manifest)) || ! hash_equals($plan['before_sha256'], self::hash($plan['before']))) {
            throw new RuntimeException('Plan does not match this manifest/backup.');
        }
    }

    public function verify(array $plan): array
    {
        $this->validatePlan($plan);
        if (self::hash($this->snapshot()) !== self::hash($this->after($plan))) {
            throw new RuntimeException('Consolidated state differs or later edits exist.');
        }
        $controller = app(\App\Http\Controllers\ProductShowController::class);
        foreach ($this->manifest['pairs'] as $pair) {
            $response = $controller(\App\Models\Product::findOrFail($pair['source']['id']));
            if (! $response instanceof \Illuminate\Http\RedirectResponse || $response->getStatusCode() !== 301 || parse_url($response->getTargetUrl(), PHP_URL_PATH) !== '/products/'.$pair['target']['slug']) {
                throw new RuntimeException('Reviewed redirect is not installed or target unavailable.');
            }
        }

        return ['archived_duplicates' => 6, 'preserved_source_records' => 6, 'verified_redirects' => 6];
    }

    public function rollback(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->validatePlan($plan);
            if (self::hash($this->snapshot(true)) !== self::hash($this->after($plan))) {
                throw new RuntimeException('Later edits detected; rollback refused.');
            }
            foreach ($plan['category_additions'] as $row) {
                DB::table('category_product')->where('product_id', $row['product_id'])->where('category_id', $row['category_id'])->delete();
            }
            $before = array_column($plan['before']['products'], null, 'id');
            foreach ($this->manifest['pairs'] as $pair) {
                $row = $before[$pair['source']['id']];
                DB::table('products')->where('id', $row['id'])->update(['status' => $row['status'], 'updated_at' => $row['updated_at']]);
            }
            if (self::hash($this->snapshot()) !== $plan['before_sha256']) {
                throw new RuntimeException('Rollback verification failed.');
            }
        });
    }

    public function verifyRenderedMetadata(): int
    {
        $entry = $this->manifest['pairs'][0]['target'];
        $scanner = new ProductSeoEditorialRepair(['repair' => 'product-seo-editorial-20261031-v1', 'entries' => [['id' => $entry['id'], 'after' => ['seo_title' => $entry['seo_title'], 'seo_description' => $entry['seo_description']]]]]);

        return $scanner->verifyRenderedMetadata();
    }

}
