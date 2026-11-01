<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSeoFinalConsolidationRepair
{
    private array $ids;

    public function __construct(private readonly array $manifest)
    {
        if (($manifest['batch'] ?? '') !== 'product-seo-consolidation-final-20261101-v1'
            || count($manifest['pairs'] ?? []) !== 3
            || count($manifest['product_updates'] ?? []) !== 6
            || ($manifest['remove_image_ids'] ?? []) !== [59711, 59712, 59713, 59714, 59715]) {
            throw new RuntimeException('Unexpected final consolidation manifest.');
        }
        $this->ids = array_column($manifest['frozen']['products'], 'id');
        sort($this->ids);
        if ($this->ids !== [9983, 9986, 10104, 10107, 12583, 12587, 12590, 12619, 12623, 12626]) {
            throw new RuntimeException('Unexpected reviewed identities.');
        }
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
        // Deleting an incorrect gallery association must not orphan another
        // product's explicitly selected polymorphic default image.
        $imageClass = \App\Models\ProductImage::class;
        $imageTypes = [$imageClass, (new $imageClass)->getMorphClass()];
        foreach (\Illuminate\Database\Eloquent\Relations\Relation::morphMap() as $alias => $mapped) {
            if ($mapped === $imageClass) {
                $imageTypes[] = $alias;
            }
        }
        $consumers = DB::table('products')->whereIn('default_image_id', $this->manifest['remove_image_ids'])->whereIn('default_image_type', array_unique($imageTypes));
        if ($lock) {
            $consumers->lockForUpdate();
        }
        if ($consumers->get(['id'])->isNotEmpty()) {
            throw new RuntimeException('Default image reference blocks gallery cleanup.');
        }
        $state['references'] = $audit['references'];

        return $state;
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function prove(array $state): void
    {
        $projection = $state;
        $expected = $this->manifest['frozen'];
        $productFields = array_fill_keys(array_keys($expected['products'][0]), true);
        $projection['products'] = array_map(static fn ($row): array => array_intersect_key($row, $productFields), $state['products']);
        if (self::hash($projection) !== self::hash($expected)) {
            throw new RuntimeException('Frozen catalogue evidence changed; review before any writes.');
        }
        foreach ($state['products'] as $product) {
            if ($product['status'] !== 'active' || $product['deleted_at'] !== null || $product['default_image_id'] !== null) {
                throw new RuntimeException('Product eligibility changed.');
            }
        }
        foreach ($state['product_variants'] as $variant) {
            if ($variant['status'] !== 'draft' || $variant['deleted_at'] !== null || $variant['price_net_amount'] !== null || $variant['price_gross_amount'] !== null) {
                throw new RuntimeException('Unreviewed priced or active variant.');
            }
        }
        foreach ($state['categories'] as $category) {
            if ($category['status'] !== 'active' || $category['deleted_at'] !== null) {
                throw new RuntimeException('Category eligibility changed.');
            }
        }
    }

    private function derived(array $state, string $timestamp): array
    {
        $updates = [];
        foreach ($this->manifest['product_updates'] as $change) {
            $updates[] = ['id' => $change['id'], 'after' => $change['after'] + ['updated_at' => $timestamp]];
        }
        $categories = [];
        foreach ($this->manifest['pairs'] as $pair) {
            $updates[] = ['id' => $pair['source']['id'], 'after' => ['status' => 'archived', 'updated_at' => $timestamp]];
            $existing = array_column(array_filter($state['category_product'], static fn ($r): bool => $r['product_id'] === $pair['target']['id']), 'category_id');
            foreach ($state['category_product'] as $row) {
                if ($row['product_id'] === $pair['source']['id'] && ! in_array($row['category_id'], $existing, true)) {
                    $categories[] = ['product_id' => $pair['target']['id'], 'category_id' => $row['category_id'], 'is_primary' => 0, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
            }
        }
        $images = array_values(array_filter($state['product_images'], fn ($row): bool => in_array($row['id'], $this->manifest['remove_image_ids'], true)));

        return ['product_updates' => $updates, 'category_additions' => $categories, 'image_removals' => $images];
    }

    private function groups(array $updates = []): array
    {
        $changes = array_column($updates, 'after', 'id');
        $titles = [];
        foreach (DB::table('products')->where('status', 'active')->whereNull('deleted_at')->get(['id', 'seo_title']) as $row) {
            $change = $changes[$row->id] ?? [];
            if (($change['status'] ?? '') !== 'archived') {
                $titles[mb_strtolower(ProductSeoMetadata::plain($change['seo_title'] ?? $row->seo_title))][] = $row->id;
            }
        }

        return array_values(array_filter($titles, static fn ($ids): bool => count($ids) > 1));
    }

    public function plan(): array
    {
        $state = $this->snapshot();
        $this->prove($state);
        $active = DB::table('products')->where('status', 'active')->whereNull('deleted_at')->count();
        if ($active !== $this->manifest['expected_active_products'] || count($this->groups()) !== $this->manifest['expected_duplicate_title_groups']) {
            throw new RuntimeException('Catalogue totals changed; review the new catalogue first.');
        }
        $timestamp = now()->format('Y-m-d H:i:s');
        $derived = $this->derived($state, $timestamp);
        $groups = $this->groups($derived['product_updates']);
        if (count($groups) !== $this->manifest['expected_after_duplicate_title_groups']) {
            throw new RuntimeException('Unexpected projected duplicate-title coverage.');
        }

        return [
            'batch' => $this->manifest['batch'], 'manifest_sha256' => self::hash($this->manifest),
            'before' => $state, 'before_sha256' => self::hash($state), 'timestamp' => $timestamp,
            ...$derived,
            'operations' => array_map(static fn ($pair): array => ['source_id' => $pair['source']['id'], 'source_slug' => $pair['source']['slug'], 'target_id' => $pair['target']['id'], 'target_slug' => $pair['target']['slug'], 'after_status' => 'archived'], $this->manifest['pairs']),
            'duplicate_title_product_ids' => $groups,
            'reasons' => ['reviewed_vicair_consolidation' => 3, 'distinct_timago_colours' => 4, 'preserved_video_links' => 2, 'excluded_off_product_images' => 5],
            'stats' => ['active_products' => $active, 'changed_products' => count($derived['product_updates']), 'archive_products' => 3, 'colour_offers_preserved' => 4, 'new_category_links' => count($derived['category_additions']), 'gallery_rows_removed_to_backup' => count($derived['image_removals']), 'files_deleted' => 0, 'changed_variants' => 0],
        ];
    }

    private function validatePlan(array $plan): void
    {
        if (($plan['batch'] ?? '') !== $this->manifest['batch'] || ! hash_equals($plan['manifest_sha256'], self::hash($this->manifest)) || ! hash_equals($plan['before_sha256'], self::hash($plan['before']))) {
            throw new RuntimeException('Plan does not match this manifest/backup.');
        }
        $this->prove($plan['before']);
        foreach ($this->derived($plan['before'], $plan['timestamp']) as $key => $value) {
            if (self::hash($value) !== self::hash($plan[$key])) {
                throw new RuntimeException('Plan operations differ from reviewed preservation scope.');
            }
        }
    }

    private function comparableAfter(array $state, array $plan): array
    {
        $newLinks = [];
        foreach ($plan['category_additions'] as $link) {
            $newLinks[$link['product_id'].':'.$link['category_id']] = true;
        }
        foreach ($state['category_product'] as &$row) {
            if (isset($newLinks[$row['product_id'].':'.$row['category_id']])) {
                unset($row['id']);
            }
        }
        unset($row);

        return $state;
    }

    private function after(array $plan): array
    {
        $state = $plan['before'];
        $updates = array_column($plan['product_updates'], 'after', 'id');
        foreach ($state['products'] as &$product) {
            $product = array_replace($product, $updates[$product['id']] ?? []);
        }
        unset($product);
        $state['category_product'] = array_merge($state['category_product'], $plan['category_additions']);
        usort($state['category_product'], static fn ($a, $b): int => [$a['product_id'], $a['category_id']] <=> [$b['product_id'], $b['category_id']]);
        $state['product_images'] = array_values(array_filter($state['product_images'], fn ($r): bool => ! in_array($r['id'], $this->manifest['remove_image_ids'], true)));
        foreach ($state['references'] as &$reference) {
            $reference['references']['category_product.product_id'] += count(array_filter($plan['category_additions'], static fn ($r): bool => $r['product_id'] === $reference['product_id']));
            $reference['references']['product_images.product_id'] -= count(array_filter($plan['image_removals'], static fn ($r): bool => $r['product_id'] === $reference['product_id']));
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
            foreach ($plan['product_updates'] as $change) {
                DB::table('products')->where('id', $change['id'])->update($change['after']);
            }
            DB::table('category_product')->insert($plan['category_additions']);
            DB::table('product_images')->whereIn('id', $this->manifest['remove_image_ids'])->delete();
            $this->verify($plan);
        });
    }

    public function verify(array $plan): array
    {
        $this->validatePlan($plan);
        if (self::hash($this->comparableAfter($this->snapshot(), $plan)) !== self::hash($this->after($plan))) {
            throw new RuntimeException('Consolidated state differs or later edits exist.');
        }
        if (DB::table('products')->where('status', 'active')->whereNull('deleted_at')->count() !== $this->manifest['expected_after_active_products'] || count($this->groups()) !== $this->manifest['expected_after_duplicate_title_groups']) {
            throw new RuntimeException('After-state catalogue totals differ.');
        }
        $controller = app(\App\Http\Controllers\ProductShowController::class);
        foreach ($this->manifest['pairs'] as $pair) {
            try {
                $response = $controller(\App\Models\Product::findOrFail($pair['source']['id']));
            } catch (Throwable $exception) {
                throw new RuntimeException('Reviewed redirect is not installed or target unavailable.', previous: $exception);
            }
            if (! $response instanceof \Illuminate\Http\RedirectResponse || $response->getStatusCode() !== 301 || parse_url($response->getTargetUrl(), PHP_URL_PATH) !== '/products/'.$pair['target']['slug']) {
                throw new RuntimeException('Reviewed redirect is not installed or target unavailable.');
            }
        }
        foreach ([9983, 9986, 10104, 10107] as $id) {
            if (app(\App\Support\Seo\ReviewedProductAliases::class)->destination(\App\Models\Product::findOrFail($id)) !== null) {
                throw new RuntimeException('A distinct colour offer was redirected.');
            }
        }

        return ['archived_duplicates' => 3, 'preserved_source_records' => 3, 'verified_redirects' => 3, 'colour_offers_preserved' => 4, 'off_product_gallery_rows_backed_up' => 5, 'preserved_extra_video_links' => 2];
    }

    public function rollback(array $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->validatePlan($plan);
            if (self::hash($this->comparableAfter($this->snapshot(true), $plan)) !== self::hash($this->after($plan))) {
                throw new RuntimeException('Later edits detected; rollback refused.');
            }
            foreach ($plan['category_additions'] as $row) {
                DB::table('category_product')->where('product_id', $row['product_id'])->where('category_id', $row['category_id'])->delete();
            }
            $before = array_column($plan['before']['products'], null, 'id');
            foreach ($plan['product_updates'] as $change) {
                $old = array_intersect_key($before[$change['id']], $change['after']);
                DB::table('products')->where('id', $change['id'])->update($old);
            }
            DB::table('product_images')->insert($plan['image_removals']);
            if (self::hash($this->snapshot()) !== $plan['before_sha256']) {
                throw new RuntimeException('Rollback verification failed.');
            }
        });
    }

    public function verifyRenderedMetadata(): int
    {
        $entry = $this->manifest['frozen']['products'][0];
        $scanner = new ProductSeoEditorialRepair(['repair' => 'product-seo-editorial-20261031-v1', 'entries' => [['id' => $entry['id'], 'after' => ['seo_title' => $entry['seo_title'], 'seo_description' => $entry['seo_description']]]]]);

        return $scanner->verifyRenderedMetadata();
    }

    public static function invalidateCache(): void
    {
        ProductSeoEditorialRepair::invalidateCache();
        app(\App\Services\Storefront\StorefrontCache::class)->bump(\App\Services\Storefront\StorefrontCache::NAMESPACE_NAVIGATION);
    }
}
