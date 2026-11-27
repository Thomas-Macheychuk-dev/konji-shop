<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\OldShop\OldShopProductImporter;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

/**
 * Moves products imported from the old shop into the shop's own categories (a map of external_id => category
 * slug, reviewed and committed with the code), then removes the brand categories the import created that are
 * left empty ("Thuasne", "Thuasne > Ortezy kolana", ...).
 *
 * Previews by default. --write records every category link and removed category first, so --rollback restores them.
 */
final class RecategoriseOldShopProductsCommand extends Command
{
    protected $signature = 'oldshop:recategorise
        {--map=resources/import-data/oldshop/thuasne-categories.json : JSON {"p4309": "ortezy-kolana", ...} in the project.}
        {--brand=Thuasne : Brand whose import categories are removed once empty.}
        {--write : Move the products and remove the empty import categories after writing a private backup.}
        {--rollback= : Restore the category links and categories recorded in a backup written by --write.}';

    protected $description = 'Move products imported from the old shop into the shop\'s own categories and remove the empty brand categories.';

    public function handle(StorefrontCache $cache): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'), $cache);
        }

        $mapPath = base_path((string) $this->option('map'));

        if (! is_file($mapPath)) {
            $this->error('MAP_NOT_FOUND='.$this->option('map'));

            return self::FAILURE;
        }

        /** @var array<string, string> $map */
        $map = json_decode((string) file_get_contents($mapPath), true, flags: JSON_THROW_ON_ERROR);
        $targets = Category::query()
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereIn('slug', array_values(array_unique($map)))
            ->get()
            ->keyBy('slug');
        $missing = array_values(array_diff(array_unique($map), $targets->keys()->all()));
        $products = Product::query()
            ->where('external_source', OldShopProductImporter::SOURCE)
            ->whereIn('external_id', array_keys($map))
            ->with('categories:id,slug')
            ->get();

        $moves = [];
        $counts = [];

        foreach ($products as $product) {
            $target = $targets->get($map[$product->external_id]);

            if ($target === null || $product->categories->pluck('id')->all() === [$target->id]) {
                continue;
            }

            $moves[$product->id] = $target->id;
            $counts[$target->slug] = ($counts[$target->slug] ?? 0) + 1;
        }

        $brandSlug = Str::slug((string) $this->option('brand'));
        $importCategories = Category::query()
            ->where(fn ($query) => $query->where('slug', $brandSlug)->orWhere('slug', 'like', $brandSlug.'-%'))
            ->get();

        $this->line('=== OLD SHOP RECATEGORISE ===');
        $this->line('MODE='.((bool) $this->option('write') ? 'WRITE' : 'PREVIEW'));
        $this->line(sprintf('MAPPED=%d FOUND=%d TO_MOVE=%d', count($map), $products->count(), count($moves)));
        ksort($counts);

        foreach ($counts as $slug => $count) {
            $this->line(sprintf('TARGET %s (%s): %d', $slug, $targets[$slug]->name, $count));
        }

        foreach ($missing as $slug) {
            $this->warn('MISSING_CATEGORY '.$slug.' (its products stay where they are)');
        }

        $this->line('IMPORT_CATEGORIES='.$importCategories->count());

        foreach ($importCategories->sortBy('slug') as $category) {
            $linked = DB::table('category_product')->where('category_id', $category->id)->pluck('product_id');
            $staying = $linked->reject(fn (int $productId): bool => isset($moves[$productId]))->count();
            $this->line(sprintf('  %s (%s): %d products, %d stay', $category->slug, $category->name, $linked->count(), $staying));
        }

        if (! (bool) $this->option('write')) {
            $this->line('Empty import categories are removed after the move.');
            $this->line('DATABASE_WRITES=0. Re-run with --write to move.');

            return self::SUCCESS;
        }

        $backup = [
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'product_ids' => array_keys($moves),
            'links_before' => DB::table('category_product')
                ->whereIn('product_id', array_keys($moves))
                ->get(['product_id', 'category_id', 'is_primary'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'removed_categories' => [],
        ];

        $removed = DB::transaction(function () use ($moves, $importCategories): array {
            foreach ($moves as $productId => $categoryId) {
                DB::table('category_product')->where('product_id', $productId)->delete();
                DB::table('category_product')->insert([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Children first, so a parent is empty once its sub-categories are gone.
            $removed = [];

            foreach ($importCategories->sortByDesc(fn (Category $category): int => $category->parent_id === null ? 0 : 1) as $category) {
                $hasProducts = DB::table('category_product')->where('category_id', $category->id)->exists();
                $hasChildren = Category::query()->where('parent_id', $category->id)->exists();

                if (! $hasProducts && ! $hasChildren) {
                    $category->delete();
                    $removed[] = $category->id;
                }
            }

            return $removed;
        });

        $backup['removed_categories'] = $removed;
        $backupPath = 'catalogue-repairs/oldshop-recategorise-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info(sprintf('MOVED=%d REMOVED_CATEGORIES=%d', count($moves), count($removed)));

        foreach (Category::query()->whereIn('id', $importCategories->pluck('id'))->get() as $kept) {
            $this->line('KEPT_CATEGORY '.$kept->slug.' (not empty)');
        }

        $this->line('BACKUP='.$backupPath);
        $this->line('Undo with: php artisan '.$this->getName().' --rollback='.$backupPath);

        return self::SUCCESS;
    }

    private function rollback(string $backupPath, StorefrontCache $cache): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('Backup not found: '.$backupPath);

            return self::FAILURE;
        }

        try {
            $backup = json_decode((string) Storage::disk('local')->get($backupPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid backup: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error('Not a '.$this->getName().' backup: '.$backupPath);

            return self::FAILURE;
        }

        DB::transaction(function () use ($backup): void {
            Category::withTrashed()->whereIn('id', $backup['removed_categories'])->restore();
            DB::table('category_product')->whereIn('product_id', $backup['product_ids'])->delete();
            DB::table('category_product')->insert(array_map(
                fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()],
                $backup['links_before'],
            ));
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info(sprintf('RESTORED_PRODUCTS=%d RESTORED_CATEGORIES=%d', count($backup['product_ids']), count($backup['removed_categories'])));

        return self::SUCCESS;
    }
}
