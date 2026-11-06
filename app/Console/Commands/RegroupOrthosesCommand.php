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
 * Moves orthoses whose name places them in a different body-part group than the one they sit in.
 *
 * catalogue:reorganise fell back to supplier category names when a name said nothing (OrthoSleeve model
 * codes), and those sometimes pointed at the wrong group. Re-running it cannot correct that: the groups'
 * own names now match too, and it never removes a link. Here only the product name counts, and products
 * whose name places them nowhere are left alone.
 *
 * Previews by default. --write records the product's group links first, so --rollback restores them.
 */
final class RegroupOrthosesCommand extends Command
{
    protected $signature = 'catalogue:regroup-orthoses
        {--write : Move the products after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the group links recorded in a backup written by --write.}';

    protected $description = 'Preview, apply or roll back moving orthoses to the body-part group their name gives.';

    private const ORTHOSES_SLUG = 'ortezy-i-stabilizatory';

    public function handle(StorefrontCache $cache): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'), $cache);
        }

        $orthoses = Category::query()->where('slug', self::ORTHOSES_SLUG)->where('status', CategoryStatus::ACTIVE->value)->first();

        if ($orthoses === null) {
            $this->error('ROOT_CATEGORY_MISSING '.self::ORTHOSES_SLUG);

            return self::FAILURE;
        }

        /** @var array<int, string> $groups id => slug */
        $groups = Category::query()
            ->where('parent_id', $orthoses->id)
            ->whereIn('slug', array_keys(OrthosisBodyPart::GROUPS))
            ->where('status', CategoryStatus::ACTIVE->value)
            ->pluck('slug', 'id')
            ->all();
        $groupIds = array_flip($groups);
        $plan = [];

        Product::query()
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', array_keys($groups)))
            ->with(['categories' => fn ($query) => $query->whereIn('categories.id', array_keys($groups))->select(['categories.id'])])
            ->select(['id', 'name'])
            ->chunkById(500, function (Collection $products) use ($groups, $groupIds, &$plan): void {
                foreach ($products as $product) {
                    $target = OrthosisBodyPart::classify((string) $product->name);
                    $current = $product->categories->map(fn (Category $category): string => $groups[$category->id])->sort()->values()->all();

                    if ($target === null || ! isset($groupIds[$target]) || in_array($target, $current, true)) {
                        continue;
                    }

                    $plan[] = ['id' => (int) $product->id, 'name' => (string) $product->name, 'from' => $current, 'to' => $target];
                }
            });

        $this->line('=== ORTHOSIS REGROUP ===');
        $this->line('TO_MOVE='.count($plan));

        foreach ($plan as $row) {
            $this->line(sprintf('MOVE product=%d %s: %s -> %s', $row['id'], Str::limit($row['name'], 70), implode(',', $row['from']), $row['to']));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        $productIds = array_column($plan, 'id');
        $backup = [
            'links_before' => DB::table('category_product')
                ->whereIn('product_id', $productIds)
                ->whereIn('category_id', array_keys($groups))
                ->get(['product_id', 'category_id', 'is_primary'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'product_ids' => $productIds,
            'scope' => array_keys($groups),
        ];

        DB::transaction(function () use ($plan, $groups, $groupIds): void {
            foreach ($plan as $row) {
                $target = $groupIds[$row['to']];
                $links = DB::table('category_product')->where('product_id', $row['id'])->whereIn('category_id', array_keys($groups))->get(['category_id', 'is_primary']);
                // The new group takes over as primary only from the group it replaces.
                $wasPrimary = $links->contains(fn (object $link): bool => (bool) $link->is_primary);

                DB::table('category_product')->where('product_id', $row['id'])->whereIn('category_id', array_keys($groups))->delete();
                DB::table('category_product')->insert([
                    'product_id' => $row['id'],
                    'category_id' => $target,
                    'is_primary' => $wasPrimary,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $backupPath = 'catalogue-repairs/regroup-orthoses-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
        ] + $backup, JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('MOVED='.count($plan));
        $this->info('CATALOGUE_REGROUP_WRITE=PASS');

        return self::SUCCESS;
    }

    private function rollback(string $backupPath, StorefrontCache $cache): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($backup): void {
            DB::table('category_product')->whereIn('product_id', $backup['product_ids'])->whereIn('category_id', $backup['scope'])->delete();
            DB::table('category_product')->insert(array_map(fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()], $backup['links_before']));
        });

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);
        $cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);

        $this->info('RESTORED_LINKS='.count($backup['links_before']));
        $this->info('CATALOGUE_REGROUP_ROLLBACK=PASS');

        return self::SUCCESS;
    }
}
