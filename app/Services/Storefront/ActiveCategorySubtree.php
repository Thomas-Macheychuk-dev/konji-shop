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
     * The subcategories below the category, without the accessory ones ("Akcesoria do ortez" and the like) and
     * what sits under them. The category itself is left out too: catalogue:reorganise links every orthosis to
     * "Ortezy i stabilizatory" as well as to its group, so that link says nothing about what a product is.
     *
     * @return list<int>
     */
    public function mainIds(Category $category): array
    {
        return $this->cache->rememberVersioned(
            StorefrontCache::NAMESPACE_NAVIGATION,
            sprintf('category-subtree-main.%d.v2', $category->getKey()),
            fn (): array => $this->queryMainIds($category),
            $this->cache->categorySidebarTtlSeconds(),
        );
    }

    /**
     * @return list<int>
     */
    private function queryIds(Category $category): array
    {
        return collect($this->queryRows($category))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function queryMainIds(Category $category): array
    {
        $root = (int) $category->getKey();
        $main = [$root => true];

        // Rows come ordered by depth, so a parent is decided before its children.
        foreach ($this->queryRows($category) as $row) {
            $id = (int) $row->id;

            if ($id !== $root && isset($main[(int) $row->parent_id]) && ! self::isAccessory((string) $row->name)) {
                $main[$id] = true;
            }
        }

        unset($main[$root]);

        return array_keys($main);
    }

    private static function isAccessory(string $name): bool
    {
        return preg_match('/^akcesoria\b/iu', trim($name)) === 1;
    }

    /**
     * @return list<object{id: int, parent_id: ?int, name: string}>
     */
    private function queryRows(Category $category): array
    {
        return DB::select(
            <<<'SQL'
WITH RECURSIVE active_category_tree AS (
    SELECT id, parent_id, name, 0 AS depth
    FROM categories
    WHERE id = ?
      AND status = ?
      AND deleted_at IS NULL

    UNION ALL

    SELECT categories.id, categories.parent_id, categories.name, active_category_tree.depth + 1
    FROM categories
    INNER JOIN active_category_tree
        ON categories.parent_id = active_category_tree.id
    WHERE categories.status = ?
      AND categories.deleted_at IS NULL
)
SELECT id, parent_id, name
FROM active_category_tree
ORDER BY depth, id
SQL,
            [
                (int) $category->getKey(),
                CategoryStatus::ACTIVE->value,
                CategoryStatus::ACTIVE->value,
            ],
        );
    }
}
