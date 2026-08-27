<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\CategoryStatus;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class ActiveCategorySubtree
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    /**
     * Return the requested category and all active descendants with one query,
     * regardless of tree depth.
     *
     * @return list<int>
     */
    public function ids(Category $category): array
    {
        return $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_NAVIGATION,
            sprintf('category-subtree.%d.v1', $category->getKey()),
            fn (): array => $this->queryIds($category),
            $this->cache->categorySidebarTtlSeconds(),
        );
    }

    /**
     * @return list<int>
     */
    private function queryIds(Category $category): array
    {
        $rows = DB::select(
            <<<'SQL'
WITH RECURSIVE active_category_tree AS (
    SELECT id, parent_id
    FROM categories
    WHERE id = ?
      AND status = ?
      AND deleted_at IS NULL

    UNION ALL

    SELECT categories.id, categories.parent_id
    FROM categories
    INNER JOIN active_category_tree
        ON categories.parent_id = active_category_tree.id
    WHERE categories.status = ?
      AND categories.deleted_at IS NULL
)
SELECT id
FROM active_category_tree
SQL,
            [
                (int) $category->getKey(),
                CategoryStatus::ACTIVE->value,
                CategoryStatus::ACTIVE->value,
            ],
        );

        return collect($rows)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
