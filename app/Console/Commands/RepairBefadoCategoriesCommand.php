<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RepairBefadoCategoriesCommand extends Command
{
    protected $signature = 'befado:repair-categories
        {--write : Apply the audited repair. Without this option, perform read-only validation.}';

    protected $description = 'Safely repair the Befado footwear category hierarchy.';

    private const GROUPS = [
        'buty-chlopiece' => 122,
        'buty-dziewczece' => 130,
        'buty-damskie' => 10,
        'buty-meskie' => 8,
    ];

    public function handle(): int
    {
        try {
            $write = (bool) $this->option('write');

            if ($write) {
                DB::transaction(function (): void {
                    $this->repair(true);
                }, 3);
            } else {
                $this->repair(false);
            }

            $this->info(
                $write
                    ? 'BEFADO_CATEGORY_REPAIR=PASS'
                    : 'BEFADO_CATEGORY_DRY_RUN=PASS'
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('BEFADO_CATEGORY_REPAIR=FAIL');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function category(string $slug): Category
    {
        $categories = Category::withTrashed()
            ->where('slug', $slug)
            ->get();

        if ($categories->count() !== 1) {
            throw new RuntimeException(
                'Expected exactly one category with slug: '.$slug
            );
        }

        $category = $categories->first();

        if ($category->trashed()) {
            throw new RuntimeException(
                'Category is soft-deleted: '.$slug
            );
        }

        return $category;
    }

    private function repair(bool $write): void
    {
        $root = $this->category('obuwie');
        $supplier = $this->category('befado');
        $supplierSite = $this->category('befadopl');

        if ($root->parent_id !== null) {
            throw new RuntimeException('Obuwie is not a root category.');
        }

        $befadoIds = Product::query()
            ->where('external_source', 'befado')
            ->pluck('id');

        if ($befadoIds->count() !== 270) {
            throw new RuntimeException(
                'Expected 270 Befado products, found '.$befadoIds->count()
            );
        }

        $groups = [];

        foreach (self::GROUPS as $slug => $expectedCount) {
            $group = $this->category($slug);

            if (! in_array($group->parent_id, [
                $supplier->id,
                $root->id,
            ], true)) {
                throw new RuntimeException(
                    'Unexpected parent for '.$slug
                );
            }

            $count = DB::table('category_product')
                ->where('category_id', $group->id)
                ->whereIn('product_id', $befadoIds)
                ->count();

            if ($count !== $expectedCount) {
                throw new RuntimeException(
                    $slug.': expected '.$expectedCount.
                    ' Befado products; found '.$count
                );
            }

            $groups[] = $group;
        }

        // Inspect every descendant, including existing leaf categories.
        // Track visited nodes so the existing supplier cycle cannot loop.
        $visited = [];
        $queue = [$supplier->id, $supplierSite->id];

        foreach ($groups as $group) {
            $queue[] = $group->id;
        }

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;

            $children = Category::withTrashed()
                ->where('parent_id', $id)
                ->get();

            foreach ($children as $child) {
                $queue[] = $child->id;
            }
        }

        $ownedIds = array_keys($visited);

        if (in_array($root->id, $ownedIds, true)) {
            throw new RuntimeException(
                'Obuwie unexpectedly belongs to the supplier subtree.'
            );
        }

        $foreignAssignments = DB::table('category_product')
            ->join(
                'products',
                'products.id',
                '=',
                'category_product.product_id'
            )
            ->whereIn('category_product.category_id', $ownedIds)
            ->where(function ($query): void {
                $query->whereNull('products.external_source')
                    ->orWhere(
                        'products.external_source',
                        '<>',
                        'befado'
                    );
            })
            ->count();

        // Category 720 (Sportowe) is shared with three FootWave products.
        // All other supplier-subtree categories must remain Befado-only.
        $sharedSports = $this->category('sportowe');

        if ($sharedSports->parent_id !== $this->category('buty-dziewczece')->id
            && $sharedSports->parent_id !== $root->id) {
            throw new RuntimeException(
                'Unexpected parent for shared Sportowe category.'
            );
        }

        $foreignSports = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where('products.external_source', 'footwave')
            ->where('category_product.is_primary', true)
            ->count();

        $otherForeignAssignments = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->whereIn('category_product.category_id', $ownedIds)
            ->where('category_product.category_id', '<>', $sharedSports->id)
            ->where(function ($query): void {
                $query->whereNull('products.external_source')
                    ->orWhere('products.external_source', '<>', 'befado');
            })
            ->count();

        // Before repair, shared Sportowe belongs to the supplier subtree.
        // After repair, it belongs directly to Obuwie and must no longer
        // contribute foreign assignments to the supplier subtree.
        $expectedSubtreeForeignAssignments =
            $sharedSports->parent_id === $root->id ? 0 : 3;

        $sharedCategoryForeignAssignments = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where(function ($query): void {
                $query->whereNull('products.external_source')
                    ->orWhere('products.external_source', '<>', 'befado');
            })
            ->count();

        if ($foreignSports !== 3
            || $sharedCategoryForeignAssignments !== 3
            || $foreignAssignments !== $expectedSubtreeForeignAssignments
            || $otherForeignAssignments !== 0) {
            throw new RuntimeException(
                'Unexpected foreign ownership in Befado subtree.'
            );
        }

        $befadoSports = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where('products.external_source', 'befado')
            ->where('category_product.is_primary', true)
            ->count();

        $girls = $this->category('buty-dziewczece');

        $girlsSports = Category::query()
            ->where('parent_id', $girls->id)
            ->where('name', 'Sportowe')
            ->where('id', '<>', $sharedSports->id)
            ->first();

        if ($girlsSports === null && $befadoSports !== 31) {
            throw new RuntimeException(
                'Expected 31 Befado primary assignments to split.'
            );
        }

        if ($girlsSports !== null && $befadoSports !== 0) {
            throw new RuntimeException(
                'Partial shared-category split detected.'
            );
        }

        if ($girlsSports !== null) {
            $foreignGirlsSports = DB::table('category_product')
                ->join('products', 'products.id', '=', 'category_product.product_id')
                ->where('category_product.category_id', $girlsSports->id)
                ->where(function ($query): void {
                    $query->whereNull('products.external_source')
                        ->orWhere('products.external_source', '<>', 'befado');
                })
                ->count();

            if ($foreignGirlsSports !== 0) {
                throw new RuntimeException(
                    'Girls Sportowe contains non-Befado assignments.'
                );
            }
        }

        $supplierLinks = [];

        foreach ([$supplier, $supplierSite] as $category) {
            $supplierLinks[$category->slug] = DB::table('category_product')
                ->where('category_id', $category->id)
                ->whereIn('product_id', $befadoIds)
                ->count();
        }

        $expectedParents = [$supplier->id, $root->id];

        foreach ($groups as $group) {
            if (! in_array($group->parent_id, $expectedParents, true)) {
                throw new RuntimeException(
                    'Unsafe group parent: '.$group->slug
                );
            }
        }

        $this->line('MODE='.($write ? 'WRITE' : 'DRY_RUN'));
        $this->line('OBUWIE_ID='.$root->id);
        $this->line('SUPPLIER_ID='.$supplier->id);
        $this->line('SUPPLIER_SITE_ID='.$supplierSite->id);
        $this->line('BEFADO_PRODUCTS='.$befadoIds->count());
        $this->line('FOREIGN_ASSIGNMENTS='.$foreignAssignments);
        $this->line('OWNED_CATEGORY_COUNT='.count($ownedIds));

        foreach ($groups as $group) {
            $this->line(
                'GROUP='.$group->slug.
                ' ID='.$group->id.
                ' CURRENT_PARENT='.$group->parent_id.
                ' TARGET_PARENT='.$root->id
            );
        }

        foreach ($supplierLinks as $slug => $count) {
            $this->line('REMOVE_ASSOCIATIONS='.$slug.':'.$count);
        }

        $this->line('SHARED_SPORTOWE_ID='.$sharedSports->id);
        $this->line('FOOTWAVE_PRIMARY_PRESERVED='.$foreignSports);
        $this->line('BEFADO_PRIMARY_TO_TRANSFER='.$befadoSports);
        $this->line('GIRLS_SPORTOWE_ID='.$girlsSports?->id);

        if (! $write) {
            $this->line('DATABASE_CHANGES=0');

            return;
        }

        // Separate the shared FootWave category from girls' footwear.
        // Retain its ID, slug, and all FootWave primary associations.
        if ($girlsSports === null) {
            $girlsSports = Category::query()->create([
                'parent_id' => $girls->id,
                'name' => 'Sportowe',
                'slug' => $this->uniqueGirlsSportsSlug(),
                'status' => $sharedSports->status,
            ]);
        }

        $befadoSportsProductIds = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where('products.external_source', 'befado')
            ->pluck('products.id')
            ->all();

        foreach ($befadoSportsProductIds as $productId) {
            DB::table('category_product')->updateOrInsert(
                [
                    'category_id' => $girlsSports->id,
                    'product_id' => $productId,
                ],
                [
                    'is_primary' => true,
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('category_product')
            ->where('category_id', $sharedSports->id)
            ->whereIn('product_id', $befadoSportsProductIds)
            ->delete();

        $sharedSports->update(['parent_id' => $root->id]);

        // Remove the cycle without deleting either historical category.
        $supplier->update(['parent_id' => null]);
        $supplierSite->update(['parent_id' => null]);

        foreach ($groups as $group) {
            $group->update(['parent_id' => $root->id]);
        }

        // Remove supplier-only breadcrumbs from Befado products.
        DB::table('category_product')
            ->whereIn('category_id', [
                $supplier->id,
                $supplierSite->id,
            ])
            ->whereIn('product_id', $befadoIds)
            ->delete();

        // Preserve existing leaf primaries and add Obuwie
        // as a non-primary ancestor.
        foreach (Product::query()
            ->where('external_source', 'befado')
            ->cursor() as $product) {
            $product->categories()->syncWithoutDetaching([
                $root->id => ['is_primary' => false],
            ]);
        }

        // Assert the resulting hierarchy before transaction commit.
        foreach ($groups as $group) {
            if ($group->fresh()->parent_id !== $root->id) {
                throw new RuntimeException(
                    'Post-write parent validation failed: '.$group->slug
                );
            }
        }

        if ($supplier->fresh()->parent_id !== null
            || $supplierSite->fresh()->parent_id !== null) {
            throw new RuntimeException(
                'Supplier category cycle still exists.'
            );
        }

        $remaining = DB::table('category_product')
            ->whereIn('category_id', [
                $supplier->id,
                $supplierSite->id,
            ])
            ->whereIn('product_id', $befadoIds)
            ->count();

        if ($remaining !== 0) {
            throw new RuntimeException(
                'Supplier category associations remain.'
            );
        }

        $rootAssignments = DB::table('category_product')
            ->where('category_id', $root->id)
            ->whereIn('product_id', $befadoIds)
            ->count();

        if ($rootAssignments !== 270) {
            throw new RuntimeException(
                'Befado Obuwie assignment count mismatch.'
            );
        }

        $remainingBefadoSports = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where('products.external_source', 'befado')
            ->count();

        $footwaveSports = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $sharedSports->id)
            ->where('products.external_source', 'footwave')
            ->where('category_product.is_primary', true)
            ->count();

        $girlsSportsCount = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('category_product.category_id', $girlsSports->id)
            ->where('products.external_source', 'befado')
            ->where('category_product.is_primary', true)
            ->count();

        if ($remainingBefadoSports !== 0
            || $footwaveSports !== 3
            || $girlsSportsCount !== 31
            || $sharedSports->fresh()->parent_id !== $root->id) {
            throw new RuntimeException(
                'Shared Sportowe post-write validation failed.'
            );
        }

        $this->line('DATABASE_CHANGES=COMMITTED_IF_TRANSACTION_SUCCEEDS');
    }

    private function uniqueGirlsSportsSlug(): string
    {
        $base = 'obuwie-buty-dziewczece-sportowe';
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
