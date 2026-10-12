<?php

declare(strict_types=1);

namespace App\Services\Qmed;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class QmedCategorySynchronizer
{
    /** @var array<string, array{name:string, parent:?string}> */
    public const TREE = [
        'qmed' => ['name' => 'Qmed', 'parent' => null],
        'qmed-szyja' => ['name' => 'Odcinek szyjny', 'parent' => 'qmed'],
        'qmed-konczyna-dolna' => ['name' => 'Kończyna dolna', 'parent' => 'qmed'],
        'qmed-konczyna-dolna-staw-kolanowy' => ['name' => 'Staw kolanowy', 'parent' => 'qmed-konczyna-dolna'],
        'qmed-konczyna-dolna-staw-skokowy' => ['name' => 'Staw skokowy', 'parent' => 'qmed-konczyna-dolna'],
        'qmed-konczyna-dolna-staw-biodrowy' => ['name' => 'Staw biodrowy', 'parent' => 'qmed-konczyna-dolna'],
        'qmed-konczyna-gorna' => ['name' => 'Kończyna górna', 'parent' => 'qmed'],
        'qmed-konczyna-gorna-staw-barkowy' => ['name' => 'Staw barkowy', 'parent' => 'qmed-konczyna-gorna'],
        'qmed-konczyna-gorna-staw-lokciowy' => ['name' => 'Staw łokciowy', 'parent' => 'qmed-konczyna-gorna'],
        'qmed-konczyna-gorna-staw-nadgarstkowy-i-dlon' => ['name' => 'Staw nadgarstkowy i dłoń', 'parent' => 'qmed-konczyna-gorna'],
        'qmed-tulow' => ['name' => 'Tułów', 'parent' => 'qmed'],
        'qmed-tulow-odcinek-ledzwiowo-krzyzowy' => ['name' => 'Odcinek lędźwiowo-krzyżowy', 'parent' => 'qmed-tulow'],
        'qmed-tulow-odcinek-ledzwiowo-krzyzowo-piersiowy' => ['name' => 'Odcinek lędźwiowo-krzyżowo-piersiowy', 'parent' => 'qmed-tulow'],
        'qmed-qmed-kids' => ['name' => 'Qmed KIDS', 'parent' => 'qmed'],
    ];

    /** @return array<string,mixed> */
    public function inspect(array $dataset): array
    {
        $errors = [];
        $products = $dataset['products'] ?? null;
        if (($dataset['source'] ?? null) !== 'qmed' || ! is_array($products) || ! array_is_list($products) || count($products) !== 156 || ($dataset['failed_urls'] ?? []) !== []) {
            return ['safe' => false, 'errors' => ['Invalid or incomplete 156-product Qmed dataset.']];
        }

        $existingCategories = Category::withTrashed()->where('slug', 'qmed')->orWhere('slug', 'like', 'qmed-%')->get()->keyBy('slug');
        foreach ($existingCategories as $slug => $category) {
            if (! isset(self::TREE[$slug]) || $category->trashed() || $category->name !== self::TREE[$slug]['name'] || $category->status !== CategoryStatus::ARCHIVED) {
                $errors[] = "Existing Qmed category is modified/unexpected: {$slug}";
            }
            $parentSlug = self::TREE[$slug]['parent'] ?? null;
            $parent = $parentSlug !== null ? $existingCategories->get($parentSlug) : null;
            if ($parentSlug === null ? $category->parent_id !== null : ($parent !== null && $category->parent_id !== $parent->id)) {
                $errors[] = "Qmed category has unexpected parent: {$slug}";
            }
        }
        $mapped = [];
        $validIds = [];
        foreach ($products as $index => $product) {
            if (! is_array($product)) {
                $errors[] = "Invalid product {$index}";

                continue;
            }
            $url = $product['source_url'] ?? null;
            $id = $product['external_id'] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            $canonical = is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'qmedinfo.pl' && ! isset($parts['query'], $parts['fragment']) && ! isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass']) && preg_match('~^/pl/oferta/(?:[^/]+/)+[^/]+$~', $parts['path'] ?? '') === 1 && ! str_contains($parts['path'], '..') ? 'https://qmedinfo.pl'.rtrim($parts['path'], '/') : null;
            if ($canonical === null || $id !== 'qmed-'.hash('sha256', $canonical) || isset($validIds[$id]) || ($product['source'] ?? null) !== 'qmed' || ($product['status'] ?? null) !== 'draft' || ($product['variants_unresolved'] ?? null) !== true) {
                $errors[] = "Invalid source identity/status {$index}";

                continue;
            }
            $validIds[$id] = true;
            $cats = $product['categories'] ?? null;
            if (! is_array($cats) || $cats === []) {
                $errors[] = "Missing memberships {$index}";

                continue;
            }
            $slugs = [];
            foreach ($cats as $cat) {
                $path = is_array($cat) ? ($cat['path'] ?? null) : null;
                if (! is_string($path) || ! str_starts_with($path, '/pl/oferta/')) {
                    $errors[] = "Invalid category path {$index}";

                    continue;
                }
                $slug = 'qmed-'.substr($path, strlen('/pl/oferta/'));
                $slug = str_replace('/', '-', $slug);
                if (! isset(self::TREE[$slug]) || ($cat['name'] ?? null) !== self::TREE[$slug]['name'] || isset($slugs[$slug])) {
                    $errors[] = "Unknown/duplicate category {$index}: {$path}";

                    continue;
                }
                $slugs[$slug] = true;
            }
            if ($slugs === []) {
                $errors[] = "No valid membership {$index}";
            }
            $mapped[$id] = array_keys($slugs);
        }
        $dbProducts = Product::withTrashed()->where('external_source', 'qmed')->get()->groupBy('external_id');
        if (count($validIds) !== 156 || $dbProducts->count() !== 156) {
            $errors[] = 'Source/database coverage must be exactly 156.';
        }
        foreach ($dbProducts as $id => $matches) {
            $record = $matches->first();
            if ($matches->count() !== 1 || ! isset($mapped[$id]) || $record->trashed() || $record->status !== ProductStatus::DRAFT || $record->published_at !== null || $record->external_parent_sku !== null || $record->variants()->withTrashed()->exists()) {
                $errors[] = "Unsafe/missing/duplicate Qmed product: {$id}";

                continue;
            }
            $attached = $record->categories()->get();
            foreach ($attached as $category) {
                if (! isset(self::TREE[$category->slug]) || ! in_array($category->slug, $mapped[$id], true) || $category->pivot->is_primary !== true && (int) $category->pivot->is_primary !== 1) {
                    $errors[] = "Unexpected pre-existing product category association: {$id}";
                }
            }
        }

        return ['safe' => $errors === [], 'errors' => array_values(array_unique($errors)), 'source_products' => count($products), 'category_definitions' => count(self::TREE), 'existing_categories' => $existingCategories->count(), 'existing_product_records' => $dbProducts->count(), 'expected_memberships' => array_sum(array_map('count', $mapped))];
    }

    /** @return array<string,int> */
    public function sync(array $dataset): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Qmed category writes are restricted to local/testing.');
        }

        return DB::transaction(function () use ($dataset): array {
            $check = $this->inspect($dataset);
            if (! $check['safe']) {
                throw new RuntimeException(implode(' | ', $check['errors']));
            }
            $categories = [];
            $created = 0;
            foreach (self::TREE as $slug => $definition) {
                $parentId = $definition['parent'] === null ? null : $categories[$definition['parent']]->id;
                $category = Category::query()->where('slug', $slug)->first();
                if ($category === null) {
                    $category = Category::create(['name' => $definition['name'], 'slug' => $slug, 'parent_id' => $parentId, 'status' => CategoryStatus::ARCHIVED]);
                    $created++;
                } elseif ($category->parent_id !== $parentId) {
                    throw new RuntimeException("Invalid parent: {$slug}");
                }
                $categories[$slug] = $category;
            }
            $memberships = 0;
            foreach ($dataset['products'] as $record) {
                $product = Product::where('external_source', 'qmed')->where('external_id', $record['external_id'])->firstOrFail();
                foreach ($record['categories'] as $sourceCategory) {
                    $slug = 'qmed-'.str_replace('/', '-', substr($sourceCategory['path'], strlen('/pl/oferta/')));
                    $categoryId = $categories[$slug]->id;
                    if (! $product->categories()->whereKey($categoryId)->exists()) {
                        $product->categories()->attach($categoryId, ['is_primary' => true]);
                        $memberships++;
                    }
                }
            }

            return ['created_categories' => $created, 'created_memberships' => $memberships, 'categories_total' => count(self::TREE)];
        }, 1);
    }
}
