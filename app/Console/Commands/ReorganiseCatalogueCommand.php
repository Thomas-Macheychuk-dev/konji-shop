<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Storefront\StorefrontCache;
use App\Support\Catalogue\OrthosisBodyPart;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Regroups "Ortezy i stabilizatory" by body part and gives party wigs their own category.
 *
 * - Every product under "Ortezy i stabilizatory" is placed in one of the shop's body-part subcategories
 *   (OrthosisBodyPart), which becomes its primary category when its primary was an orthosis category.
 *   Products no rule places stay directly under "Ortezy i stabilizatory". The ~220 supplier subcategories
 *   are then archived; their URLs redirect to the nearest active parent.
 * - Party and carnival wigs leave "Peruki i akcesoria" for a new top-level "Peruki imprezowe i karnawałowe".
 *
 * Previews by default. --write records every touched category and product link first, so --rollback
 * restores the tree exactly.
 */
final class ReorganiseCatalogueCommand extends Command
{
    protected $signature = 'catalogue:reorganise
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the categories and product links recorded in a backup written by --write.}';

    protected $description = 'Preview, apply or roll back the body-part orthosis categories and the party wig category.';

    private const ORTHOSES_SLUG = 'ortezy-i-stabilizatory';

    private const WIGS_SLUG = 'peruki';

    private const PARTY_WIGS = ['slug' => 'peruki-imprezowe-i-karnawalowe', 'name' => 'Peruki imprezowe i karnawałowe'];

    public function handle(StorefrontCache $cache): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'), $cache);
        }

        $orthoses = Category::query()->where('slug', self::ORTHOSES_SLUG)->where('status', CategoryStatus::ACTIVE->value)->first();
        $wigs = Category::query()->where('slug', self::WIGS_SLUG)->where('status', CategoryStatus::ACTIVE->value)->first();

        if ($orthoses === null || $wigs === null) {
            $this->error('ROOT_CATEGORY_MISSING orthoses='.($orthoses ? 'ok' : 'missing').' wigs='.($wigs ? 'ok' : 'missing'));

            return self::FAILURE;
        }

        $orthosisTree = $this->descendantIds($orthoses);
        $targetSlugs = array_keys(OrthosisBodyPart::GROUPS);
        $existingTargets = Category::withTrashed()->whereIn('slug', [...$targetSlugs, self::PARTY_WIGS['slug']])->get()->keyBy('slug');
        $toArchive = Category::query()
            ->whereKey($orthosisTree)
            ->whereNotIn('slug', $targetSlugs)
            ->where('status', CategoryStatus::ACTIVE->value)
            ->pluck('id')
            ->all();

        $orthosisPlan = $this->orthosisPlan($orthoses, $orthosisTree);
        $wigTree = [$wigs->id, ...$this->descendantIds($wigs)];
        $partyWigs = Product::query()
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $wigTree))
            ->get(['id', 'name'])
            ->filter(fn (Product $product): bool => OrthosisBodyPart::isPartyWig((string) $product->name))
            ->values();

        $this->line('=== CATALOGUE REORGANISE ===');
        $this->line('ORTHOSIS_PRODUCTS='.count($orthosisPlan).' UNPLACED='.count(array_filter($orthosisPlan, fn ($row) => $row['group'] === null)));

        foreach (array_count_values(array_map(fn ($row) => $row['group'] ?? '-', $orthosisPlan)) as $group => $count) {
            $existing = $existingTargets->get($group);
            $this->line(sprintf('  GROUP %-30s %5d %s', $group, $count, match (true) {
                $group === '-' => '(stays under Ortezy i stabilizatory)',
                $existing !== null => sprintf('(existing category %d, parent %s)', $existing->id, $existing->parent_id === $orthoses->id ? 'Ortezy i stabilizatory' : (string) ($existing->parent_id ?? 'none')),
                default => '(new category)',
            }));
        }

        $this->line('SUPPLIER_SUBCATEGORIES_TO_ARCHIVE='.count($toArchive));
        $this->line('PARTY_WIGS_TO_MOVE='.$partyWigs->count().' -> '.self::PARTY_WIGS['name']);

        foreach (array_filter($orthosisPlan, fn ($row) => $row['group'] === null) as $row) {
            $this->line('UNPLACED product='.$row['id'].' '.Str::limit($row['name'], 80));
        }

        foreach ($partyWigs->take(5) as $product) {
            $this->line('PARTY_WIG product='.$product->id.' '.Str::limit((string) $product->name, 80));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        $backup = DB::transaction(function () use ($orthoses, $wigTree, $orthosisPlan, $toArchive, $partyWigs, $existingTargets, $orthosisTree): array {
            $productIds = array_values(array_unique([...array_column($orthosisPlan, 'id'), ...$partyWigs->modelKeys()]));
            $scope = array_values(array_unique([$orthoses->id, ...$orthosisTree, ...$wigTree, ...$existingTargets->modelKeys()]));

            $backup = [
                'links_before' => DB::table('category_product')
                    ->whereIn('product_id', $productIds)
                    ->whereIn('category_id', $scope)
                    ->get(['product_id', 'category_id', 'is_primary'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
                'categories_before' => Category::withTrashed()
                    ->whereKey([...$toArchive, ...$existingTargets->modelKeys()])
                    ->get()
                    ->map(fn (Category $category): array => [
                        'id' => $category->id,
                        'parent_id' => $category->parent_id,
                        'name' => $category->name,
                        'status' => $category->status->value,
                        'deleted_at' => $category->deleted_at?->toIso8601String(),
                    ])
                    ->all(),
                'created_categories' => [],
                'product_ids' => $productIds,
                // Every category whose links this run may change; rollback resets links inside it.
                'scope' => $scope,
            ];

            $targets = [];

            foreach (OrthosisBodyPart::GROUPS as $slug => [$name]) {
                $category = $existingTargets->get($slug);

                if ($category === null) {
                    $category = Category::query()->create(['slug' => $slug, 'name' => $name, 'parent_id' => $orthoses->id, 'status' => CategoryStatus::ACTIVE]);
                    $backup['created_categories'][] = $category->id;
                } else {
                    if ($category->trashed()) {
                        $category->restore();
                    }

                    $category->update(['name' => $name, 'parent_id' => $orthoses->id, 'status' => CategoryStatus::ACTIVE]);
                }

                $targets[$slug] = $category->id;
            }

            $orthosisScope = array_values(array_unique([$orthoses->id, ...$orthosisTree, ...array_values($targets)]));

            foreach ($orthosisPlan as $row) {
                $target = $row['group'] === null ? $orthoses->id : $targets[$row['group']];
                $this->placeProduct($row['id'], [$orthoses->id, $target], $target, $orthosisScope, removeOthers: false);
            }

            $partyCategory = $existingTargets->get(self::PARTY_WIGS['slug']);

            if ($partyCategory === null) {
                $partyCategory = Category::query()->create([
                    'slug' => self::PARTY_WIGS['slug'],
                    'name' => self::PARTY_WIGS['name'],
                    'parent_id' => null,
                    'status' => CategoryStatus::ACTIVE,
                ]);
                $backup['created_categories'][] = $partyCategory->id;
            } else {
                if ($partyCategory->trashed()) {
                    $partyCategory->restore();
                }

                $partyCategory->update(['parent_id' => null, 'status' => CategoryStatus::ACTIVE]);
            }

            foreach ($partyWigs as $product) {
                $this->placeProduct($product->id, [$partyCategory->id], $partyCategory->id, $wigTree, removeOthers: true);
            }

            // Saved through the model so the storefront cache observers run.
            foreach (Category::query()->whereKey($toArchive)->get() as $category) {
                $category->status = CategoryStatus::ARCHIVED;
                $category->save();
            }

            return $backup;
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $backupPath = 'catalogue-repairs/reorganise-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
        ] + $backup, JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('PRODUCTS_PLACED='.count($orthosisPlan).' PARTY_WIGS_MOVED='.$partyWigs->count().' CATEGORIES_ARCHIVED='.count($toArchive));
        $this->info('CATALOGUE_REORGANISE_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $tree
     * @return list<array{id: int, name: string, group: ?string}>
     */
    private function orthosisPlan(Category $orthoses, array $tree): array
    {
        $scope = [$orthoses->id, ...$tree];
        $plan = [];

        Product::query()
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $scope))
            ->with(['categories' => fn ($query) => $query->whereIn('categories.id', $scope)->select(['categories.id', 'categories.name'])])
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $products) use (&$plan): void {
                foreach ($products as $product) {
                    $plan[] = [
                        'id' => (int) $product->id,
                        'name' => (string) $product->name,
                        'group' => OrthosisBodyPart::classify((string) $product->name, $product->categories->pluck('name')->all()),
                    ];
                }
            });

        return $plan;
    }

    /**
     * Link the product to the given categories and make $primary its primary category when its current
     * primary lies in $scope (or it has none). With $removeOthers, its other links inside $scope go.
     *
     * @param  list<int>  $link
     * @param  list<int>  $scope
     */
    private function placeProduct(int $productId, array $link, int $primary, array $scope, bool $removeOthers): void
    {
        $rows = DB::table('category_product')->where('product_id', $productId)->get(['category_id', 'is_primary']);
        $primaryRow = $rows->first(fn (object $row): bool => (bool) $row->is_primary);
        $takePrimary = $primaryRow === null || in_array((int) $primaryRow->category_id, $scope, true);

        if ($removeOthers) {
            DB::table('category_product')
                ->where('product_id', $productId)
                ->whereIn('category_id', array_diff($scope, $link))
                ->delete();
        }

        if ($takePrimary) {
            DB::table('category_product')->where('product_id', $productId)->update(['is_primary' => false]);
        }

        foreach ($link as $categoryId) {
            $isPrimary = $takePrimary && $categoryId === $primary;
            $exists = DB::table('category_product')->where('product_id', $productId)->where('category_id', $categoryId)->exists();

            if ($exists) {
                if ($isPrimary) {
                    DB::table('category_product')->where('product_id', $productId)->where('category_id', $categoryId)->update(['is_primary' => true]);
                }

                continue;
            }

            DB::table('category_product')->insert([
                'product_id' => $productId,
                'category_id' => $categoryId,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Every descendant of the category, whatever its status.
     *
     * @return list<int>
     */
    private function descendantIds(Category $category): array
    {
        $ids = [];
        $frontier = [$category->id];

        while ($frontier !== []) {
            $children = Category::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $children = array_values(array_diff($children, [$category->id]));
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return $ids;
    }

    private function rollback(string $backupPath, StorefrontCache $cache): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($backup): void {
            $scope = array_values(array_unique([...$backup['scope'], ...$backup['created_categories']]));

            foreach (array_chunk($backup['product_ids'], 500) as $productIds) {
                DB::table('category_product')->whereIn('product_id', $productIds)->whereIn('category_id', $scope)->delete();
            }

            foreach (array_chunk($backup['links_before'], 500) as $rows) {
                DB::table('category_product')->insert(array_map(fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()], $rows));
            }

            foreach ($backup['categories_before'] as $row) {
                $category = Category::withTrashed()->find($row['id']);

                if ($category === null) {
                    continue;
                }

                $category->forceFill([
                    'parent_id' => $row['parent_id'],
                    'name' => $row['name'],
                    'status' => CategoryStatus::from($row['status']),
                    'deleted_at' => $row['deleted_at'],
                ])->save();
            }

            foreach (Category::query()->whereKey($backup['created_categories'])->get() as $category) {
                $category->forceDelete();
            }
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info('RESTORED_LINKS='.count($backup['links_before']).' RESTORED_CATEGORIES='.count($backup['categories_before']).' REMOVED_CATEGORIES='.count($backup['created_categories']));
        $this->info('CATALOGUE_REORGANISE_ROLLBACK=PASS');

        return self::SUCCESS;
    }
}
