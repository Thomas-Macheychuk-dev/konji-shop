<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The active top-level categories, each with the number of active products in its whole active subtree.
 * Shared by the homepage and the /categories page.
 */
final class RootCategories
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    /**
     * @return Collection<int, Category>
     */
    public function withProductCounts(): Collection
    {
        return $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_CATALOGUE,
            'home.root-categories.v2',
            function () {
                $categories = Category::query()
                    ->whereNull('parent_id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->whereNotNull('slug')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'description']);

                $activeProductCounts = collect(DB::select(
                    <<<'SQL'
WITH RECURSIVE active_category_tree AS (
    SELECT id, id AS root_id
    FROM categories
    WHERE parent_id IS NULL
      AND status = ?
      AND deleted_at IS NULL

    UNION ALL

    SELECT categories.id, active_category_tree.root_id
    FROM categories
    INNER JOIN active_category_tree
        ON categories.parent_id = active_category_tree.id
    WHERE categories.status = ?
      AND categories.deleted_at IS NULL
)
SELECT
    active_category_tree.root_id,
    COUNT(DISTINCT products.id) AS active_products_count
FROM active_category_tree
LEFT JOIN category_product
    ON category_product.category_id = active_category_tree.id
LEFT JOIN products
    ON products.id = category_product.product_id
   AND products.status = ?
   AND products.deleted_at IS NULL
GROUP BY active_category_tree.root_id
SQL,
                    [
                        CategoryStatus::ACTIVE->value,
                        CategoryStatus::ACTIVE->value,
                        ProductStatus::ACTIVE->value,
                    ],
                ))->mapWithKeys(
                    fn (object $row): array => [
                        (int) $row->root_id => (int) $row->active_products_count,
                    ],
                );

                return $categories->each(function (Category $category) use ($activeProductCounts): void {
                    $category->setAttribute(
                        'active_products_count',
                        $activeProductCounts->get((int) $category->id, 0),
                    );
                });
            },
            $this->cache->homePageTtlSeconds(),
        );
    }
}
