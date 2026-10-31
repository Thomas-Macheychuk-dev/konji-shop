<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

final class ProductSeoEditorialExport
{
    public const VERSION = 'product-seo-editorial-20261031-v1';

    public function __construct(private readonly array $reviewed) {}

    public function build(array $plan): array
    {
        if (($plan['repair'] ?? null) !== 'product-seo-database-20261031-v1') {
            throw new RuntimeException('Unexpected database repair plan.');
        }
        $products = DB::table('products')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description', 'published_at'])->keyBy('id');
        $groups = $products->groupBy(fn ($p): string => mb_strtolower(ProductSeoMetadata::plain($p->seo_title ?: $p->name)))
            ->filter(fn ($rows): bool => $rows->count() > 1)->values();
        $basic = [];
        $changed = [];
        foreach ($plan['operations'] as $operation) {
            if (array_intersect($operation['reasons'], ['fill_description', 'replace_contaminated_description']) === []) {
                continue;
            }
            $product = $products->get($operation['id']);
            if ($product === null || $product->slug !== $operation['slug'] || $product->name !== $operation['name']
                || $product->seo_title !== $operation['after']['seo_title'] || $product->seo_description !== $operation['after']['seo_description']) {
                $changed[] = $operation['id'];
                continue;
            }
            // Reconstruct the no-attribute proposal. Exact equality identifies the
            // original sparse fallback, including long names without the CTA suffix.
            $before = (array) $product;
            $before['seo_title'] = $operation['before']['seo_title'];
            $before['seo_description'] = $operation['before']['seo_description'];
            $proposal = ProductSeoMetadata::propose($before, [], $this->reviewed);
            if ($proposal['after']['seo_description'] === $operation['after']['seo_description']) {
                $basic[] = $product->id;
            }
        }
        $missing = $products->filter(fn ($p): bool => ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($p->seo_title))
            || ProductSeoMetadata::placeholder(ProductSeoMetadata::plain($p->seo_description)))->keys()->all();
        $duplicateIds = $groups->flatten(1)->pluck('id')->all();
        $ids = array_values(array_unique(array_merge($basic, $duplicateIds, $missing)));
        sort($ids);
        $categories = DB::table('categories')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'parent_id', 'name', 'slug', 'description', 'seo_title', 'seo_description'])->keyBy('id');
        $copyPath = resource_path('content/category-copy.pl.php');
        $categoryCopy = is_file($copyPath) ? require $copyPath : [];
        $categoryRows = [];
        foreach ($categories as $category) {
            $row = (array) $category;
            $entry = $categoryCopy[$category->slug] ?? [];
            $row['editorial_fallback'] = ($entry['name'] ?? null) === $category->name ? $entry : null;
            $categoryRows[] = $row;
        }
        $out = [];
        foreach (array_chunk($ids, 150) as $chunk) {
            $details = DB::table('products')->whereIn('id', $chunk)->orderBy('id')
                ->get(['id', 'name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description', 'published_at', 'short_description', 'description']);
            $attributes = DB::table('product_attribute_value as pivot')
                ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
                ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
                ->whereIn('pivot.product_id', $chunk)->orderBy('pivot.product_id')->orderBy('attribute.id')->orderBy('value.id')
                ->get(['pivot.product_id', 'attribute.id as attribute_id', 'attribute.external_attribute_id', 'attribute.name as attribute', 'value.id as value_id', 'value.value'])
                ->groupBy('product_id');
            $variants = DB::table('product_variants as variant')
                ->join('product_variant_attribute_value as pivot', 'pivot.product_variant_id', '=', 'variant.id')
                ->join('attribute_values as value', 'value.id', '=', 'pivot.attribute_value_id')
                ->join('attributes as attribute', 'attribute.id', '=', 'value.attribute_id')
                ->whereIn('variant.product_id', $chunk)->where('variant.status', 'active')->whereNull('variant.deleted_at')
                ->select(['variant.product_id', 'attribute.id as attribute_id', 'attribute.external_attribute_id', 'attribute.name as attribute', 'value.id as value_id', 'value.value'])
                ->distinct()->orderBy('variant.product_id')->orderBy('attribute.id')->orderBy('value.value')->get()->groupBy('product_id');
            $variantCounts = DB::table('product_variants')->whereIn('product_id', $chunk)->where('status', 'active')->whereNull('deleted_at')
                ->select('product_id')->selectRaw('COUNT(*) as variant_count')->groupBy('product_id')->pluck('variant_count', 'product_id');
            $defaultSkus = DB::table('product_variants')->whereIn('product_id', $chunk)->where('status', 'active')->whereNull('deleted_at')
                ->where('is_default', true)->orderBy('id')->get(['product_id', 'sku', 'external_variant_id'])->groupBy('product_id');
            $memberships = DB::table('category_product')->whereIn('product_id', $chunk)->orderBy('product_id')->orderByDesc('is_primary')->orderBy('category_id')
                ->get(['product_id', 'category_id', 'is_primary'])->groupBy('product_id');
            $images = DB::table('product_images')->whereIn('product_id', $chunk)->orderBy('product_id')->orderByDesc('is_main')->orderBy('sort_order')->orderBy('id')
                ->get(['product_id', 'disk', 'path', 'source_url', 'alt_text'])->groupBy('product_id');
            $attributeImages = DB::table('product_attribute_value_images')->whereIn('product_id', $chunk)->orderBy('product_id')->orderByDesc('is_main')->orderBy('sort_order')->orderBy('id')
                ->get(['product_id', 'attribute_value_id', 'disk', 'path', 'source_url', 'alt_text'])->groupBy('product_id');
            foreach ($details as $product) {
                $row = (array) $product;
                $row['review_reasons'] = array_values(array_filter([
                    in_array($product->id, $basic, true) ? 'basic_generated_description' : null,
                    in_array($product->id, $duplicateIds, true) ? 'duplicate_seo_title' : null,
                    in_array($product->id, $missing, true) ? 'missing_metadata_now' : null,
                ]));
                foreach (['short_description' => 3000, 'description' => 12000] as $field => $limit) {
                    $plain = ProductSeoMetadata::plain($row[$field]);
                    $row[$field] = mb_substr($plain, 0, $limit);
                    $row[$field.'_truncated'] = mb_strlen($plain) > $limit;
                }
                $row['product_attributes'] = $this->attributeRows($attributes->get($product->id, collect())->all());
                $row['active_variant_attribute_values'] = $this->attributeRows($variants->get($product->id, collect())->all());
                $row['active_variant_count'] = (int) $variantCounts->get($product->id, 0);
                $row['default_variant_identifiers'] = $defaultSkus->get($product->id, collect())->map(fn ($v): array => ['sku' => $v->sku, 'external_variant_id' => $v->external_variant_id])->values()->all();
                $row['categories'] = [];
                foreach ($memberships->get($product->id, collect()) as $membership) {
                    $category = $categories->get($membership->category_id);
                    if ($category === null) {
                        continue;
                    }
                    $path = [];
                    $seen = [];
                    $current = $category;
                    while ($current !== null && ! isset($seen[$current->id])) {
                        $seen[$current->id] = true;
                        array_unshift($path, $current->name);
                        $current = $categories->get($current->parent_id);
                    }
                    $row['categories'][] = ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'is_primary' => (bool) $membership->is_primary, 'path' => $path];
                }
                $row['images'] = $images->get($product->id, collect())->take(3)->map(fn ($image): array => ['disk' => $image->disk, 'path' => $image->path, 'source_url' => $image->source_url, 'alt_text' => $image->alt_text])->values()->all();
                $row['attribute_images'] = $attributeImages->get($product->id, collect())->take(3)->map(fn ($image): array => ['attribute_value_id' => $image->attribute_value_id, 'disk' => $image->disk, 'path' => $image->path, 'source_url' => $image->source_url, 'alt_text' => $image->alt_text])->values()->all();
                $out[] = $row;
            }
        }

        return [
            'version' => self::VERSION, 'exported_at' => now()->toIso8601String(), 'database_writes' => false,
            'summary' => [
                'active_products' => $products->count(), 'active_categories' => $categories->count(),
                'original_basic_description_count' => $plan['stats']['minimal_generated_descriptions'],
                'current_unchanged_basic_descriptions' => count($basic), 'changed_since_repair_product_ids' => $changed,
                'duplicate_title_groups' => $groups->count(), 'missing_metadata_product_ids' => $missing,
                'exported_products' => count($out),
            ],
            'duplicate_groups' => $groups->map(fn ($rows): array => $rows->pluck('id')->values()->all())->all(),
            'products' => $out, 'categories' => $categoryRows,
        ];
    }

    private function attributeRows(array $rows): array
    {
        return array_map(function ($object): array {
            $row = (array) $object;
            unset($row['product_id']);
            $value = ProductSeoMetadata::plain($row['value']);
            $row['value'] = mb_substr($value, 0, 500);
            $row['value_truncated'] = mb_strlen($value) > 500;

            return $row;
        }, $rows);
    }
}
