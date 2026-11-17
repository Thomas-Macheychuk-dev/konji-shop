<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\Products\ClothingProductNames;
use App\Support\Seo\ProductIdentifiers;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Renames medical and work clothing to "{producer} {model} {garment}" (owner's feedback, 16 November 2026),
 * e.g. "Eldan Lena Sukienka" and "Eldan Lena Flex Sukienka Krótki Rękaw". See ClothingProductNames.
 *
 * Covers the products in the clothing category tree except disposables, masks, caps, accessories and
 * underwear. The old name becomes the SEO title when the product has none, so the page title keeps the
 * descriptive words. Slugs stay the same, so product URLs do not change. Safe to run again after an import:
 * names that already start with the producer are left alone.
 */
final class RenameClothingProductsCommand extends Command
{
    protected $signature = 'catalogue:clothing-names
        {--category=odziez-medyczna : Slug of the clothing category tree.}
        {--write : Rename the products after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the names from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back naming clothing as producer, model and garment.';

    /** Subcategories that are not garments named by model. */
    private const EXCLUDED_CATEGORIES = [
        'Fartuchy i odzież jednorazowa',
        'Ochraniacze na obuwie',
        'Maseczki ochronne',
        'Czepki jednorazowe',
        'Czepki chirurgiczne',
        'Czepki chirurgiczne we wzorki',
        'Akcesoria',
        'Bielizna',
        'Bielizna poporodowa',
        'Bielizna ciążowa',
    ];

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $root = Category::query()->where('slug', (string) $this->option('category'))->first();

        if ($root === null) {
            $this->error('CATEGORY_NOT_FOUND='.$this->option('category'));

            return self::FAILURE;
        }

        [$included, $excluded] = $this->categoryIds($root);

        $products = Product::query()
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $included))
            ->whereDoesntHave('categories', fn ($query) => $query->whereIn('categories.id', $excluded))
            ->with('attributeValues.attribute')
            ->orderBy('id')
            ->get();

        $inScope = [];
        $noBrand = [];

        foreach ($products as $product) {
            $brand = ProductIdentifiers::brand($product);

            if ($brand === null) {
                $noBrand[] = ['id' => $product->id, 'name' => (string) $product->name, 'reason' => 'NO_PRODUCER'];

                continue;
            }

            $inScope[] = ['id' => $product->id, 'name' => (string) $product->name, 'brand' => $brand];
        }

        // A new name must not repeat the name of a product outside the clothing tree either.
        $otherNames = Product::query()
            ->whereNotIn('id', $products->modelKeys())
            ->whereIn('name', array_column(ClothingProductNames::plan($inScope)['renames'], 'new'))
            ->pluck('name')
            ->all();

        $plan = ClothingProductNames::plan($inScope, $otherNames);
        $skipped = array_merge($noBrand, $plan['skipped']);
        $seoTitles = $products->keyBy('id')->map(fn (Product $product): ?string => $product->seo_title);

        $renames = array_map(fn (array $rename): array => $rename + [
            'old_seo_title' => $seoTitles[$rename['id']],
            'new_seo_title' => blank($seoTitles[$rename['id']]) ? $rename['old'] : $seoTitles[$rename['id']],
        ], $plan['renames']);

        $this->line('=== CLOTHING NAMES ===');
        $this->line(sprintf('PRODUCTS=%d RENAMES=%d SKIPPED=%d', $products->count(), count($renames), count($skipped)));

        foreach ($renames as $rename) {
            $this->line(sprintf('RENAME product=%d "%s" -> "%s"', $rename['id'], $rename['old'], $rename['new']));
        }

        foreach ($skipped as $skip) {
            $this->line(sprintf('SKIP %s product=%d "%s"', $skip['reason'], $skip['id'], $skip['name']));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($renames === []) {
            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/clothing-names-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'renames' => $renames,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('RENAMED='.$this->apply($renames, 'old', 'new'));

        return self::SUCCESS;
    }

    /**
     * Ids of the category tree and of its excluded subtrees.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function categoryIds(Category $root): array
    {
        $children = Category::query()->get(['id', 'parent_id', 'name'])->groupBy('parent_id');
        $included = [];
        $excluded = [];
        $queue = [[$root, false]];

        while ($queue !== []) {
            [$category, $isExcluded] = array_shift($queue);
            $isExcluded = $isExcluded || in_array($category->name, self::EXCLUDED_CATEGORIES, true);

            if ($isExcluded) {
                $excluded[] = $category->id;
            } else {
                $included[] = $category->id;
            }

            foreach ($children->get($category->id, new Collection) as $child) {
                $queue[] = [$child, $isExcluded];
            }
        }

        return [$included, $excluded];
    }

    /** @param  list<array<string, mixed>>  $renames */
    private function apply(array $renames, string $from, string $to): int
    {
        $written = 0;

        foreach ($renames as $rename) {
            $product = Product::query()->find($rename['id']);

            if ($product === null || $product->name !== $rename[$from]) {
                $this->warn('SKIPPED_CHANGED_SINCE product='.$rename['id']);

                continue;
            }

            // Saved through the model so storefront cache invalidation observers run.
            $product->name = $rename[$to];
            $product->seo_title = $rename[$to.'_seo_title'];
            $product->save();
            $written++;
        }

        return $written;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $this->info('RESTORED='.$this->apply($backup['renames'], 'new', 'old').' OF '.count($backup['renames']));

        return self::SUCCESS;
    }
}
