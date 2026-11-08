<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Products\ProductBrandAttribute;
use App\Services\Storefront\ProductListing;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives products without a "Producent"/"Marka" value a brand, so their pages can show GPSR manufacturer details.
 *
 * - Suppliers that sell only their own products get that brand as "Producent".
 * - Otherwise a product takes the one existing brand its name mentions as a whole word ("… Ziaja Intima 500 ml").
 *   Names mentioning several brands are left alone, as are brands shorter than four letters and brands
 *   whose name is also a common product name.
 *
 * Only links to brand values are added; every link is backed up so --rollback removes exactly those.
 */
final class AssignMissingBrandsCommand extends Command
{
    protected $signature = 'catalogue:assign-missing-brands
        {--write : Apply the changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Remove the brand links recorded in a backup written by --write.}
        {--samples=5 : Number of sample products to print per brand.}';

    protected $description = 'Preview, apply or roll back giving products without a manufacturer the brand of their supplier or product name.';

    private const BACKUP_DIRECTORY = 'catalogue-repairs';

    /** Suppliers whose catalogue is only their own brand: external_source => brand. */
    private const SOURCE_BRANDS = [
        'herdegen' => 'Herdegen',
        'wojdak' => 'Wojdak',
    ];

    private const MIN_NAME_MATCH_LENGTH = 4;

    /**
     * Brands whose name is also a common product or model name, never matched by name:
     * TZMO's "Bella" against "BELLA VITA" wig pieces or a "BELLA FLEX" top.
     */
    private const NAME_MATCH_EXCLUDED = ['bella', 'happy'];

    public function handle(ProductBrandAttribute $brandAttribute, StorefrontCache $cache): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'), $cache);
        }

        $brandNames = $this->brandNames();
        $assignments = [];
        $unmatched = [];
        $ambiguous = 0;

        $this->productsWithoutBrand()->chunkById(500, function ($products) use ($brandNames, &$assignments, &$unmatched, &$ambiguous): void {
            foreach ($products as $product) {
                $source = (string) $product->external_source;
                $assignment = ['product_id' => $product->id, 'product' => (string) $product->name, 'source' => $source];

                if (isset(self::SOURCE_BRANDS[$source])) {
                    $assignments[] = $assignment + ['rule' => 'source', 'value_id' => null, 'brand' => self::SOURCE_BRANDS[$source]];

                    continue;
                }

                $matches = self::brandsInName((string) $product->name, $brandNames);

                if (count($matches) === 1) {
                    $valueId = array_key_first($matches);
                    $assignments[] = $assignment + ['rule' => 'name', 'value_id' => $valueId, 'brand' => $matches[$valueId]];

                    continue;
                }

                $ambiguous += count($matches) > 1 ? 1 : 0;
                $key = $source !== '' ? $source : '(none)';
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
            }
        });

        $this->report($assignments, $unmatched, $ambiguous);

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($assignments === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $attached = DB::transaction(function () use ($assignments, $brandAttribute): array {
            $attached = [];

            foreach ($assignments as $assignment) {
                $valueId = $assignment['value_id'] ?? $brandAttribute->value($assignment['brand'])->id;

                if (DB::table('product_attribute_value')->where('product_id', $assignment['product_id'])->where('attribute_value_id', $valueId)->exists()) {
                    continue;
                }

                DB::table('product_attribute_value')->insert([
                    'product_id' => $assignment['product_id'],
                    'attribute_value_id' => $valueId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $attached[] = ['product_id' => $assignment['product_id'], 'value_id' => $valueId];
            }

            return $attached;
        });

        $backupPath = self::BACKUP_DIRECTORY.'/assign-missing-brands-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'attached' => $attached,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // Pivot inserts do not fire product observers, so cached product pages and brand filters are invalidated here.
        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

        $this->info('BACKUP='.$backupPath);
        $this->info('ATTACHED='.count($attached));
        $this->info('ASSIGN_MISSING_BRANDS_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * The brands a product name mentions as whole words, in any letter case. A brand inside a longer
     * mentioned brand ("Medi" in "Medi Trade") yields to it.
     *
     * @param  array<int, string>  $brandNames  value id => name
     * @return array<int, string>
     */
    public static function brandsInName(string $name, array $brandNames): array
    {
        $mentioned = array_filter($brandNames, fn (string $brand): bool => self::mentions($name, $brand));

        return array_filter($mentioned, function (string $brand) use ($mentioned): bool {
            foreach ($mentioned as $other) {
                if (mb_strlen($other) > mb_strlen($brand) && self::mentions($other, $brand)) {
                    return false;
                }
            }

            return true;
        });
    }

    private static function mentions(string $text, string $brand): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($brand, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    /**
     * @return Builder<Product>
     */
    private function productsWithoutBrand()
    {
        return Product::query()
            ->whereDoesntHave('attributeValues', fn ($query) => $query
                ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS)))
            ->select(['id', 'name', 'external_source']);
    }

    /**
     * Names of brand values some product already uses, one value per name ("Producent" first).
     *
     * @return array<int, string> value id => name
     */
    private function brandNames(): array
    {
        $names = [];

        AttributeValue::query()
            ->with('attribute')
            ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ProductListing::BRAND_ATTRIBUTE_SLUGS))
            ->whereHas('products')
            ->get()
            ->sortBy(fn (AttributeValue $value): int => (int) array_search($value->attribute?->slug, ProductListing::BRAND_ATTRIBUTE_SLUGS, true))
            ->each(function (AttributeValue $value) use (&$names): void {
                $name = trim((string) $value->value);

                if (mb_strlen($name) >= self::MIN_NAME_MATCH_LENGTH
                    && ! in_array(mb_strtolower($name), self::NAME_MATCH_EXCLUDED, true)
                    && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true)) {
                    $names[$value->id] = $name;
                }
            });

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  array<string, int>  $unmatched
     */
    private function report(array $assignments, array $unmatched, int $ambiguous): void
    {
        $rules = collect($assignments)->countBy('rule');
        arsort($unmatched);

        $this->line('=== ASSIGN MISSING BRANDS ===');
        $this->line('ASSIGNABLE='.count($assignments).' (BY_SOURCE='.($rules['source'] ?? 0).', BY_NAME='.($rules['name'] ?? 0).')');
        $this->line('AMBIGUOUS_NAMES='.$ambiguous);
        $this->line('STILL_WITHOUT_BRAND='.array_sum($unmatched).' '.json_encode($unmatched, JSON_UNESCAPED_UNICODE));

        foreach (collect($assignments)->groupBy('brand')->sortByDesc(fn (Collection $group): int => $group->count()) as $brand => $group) {
            $sources = $group->countBy('source')->map(fn (int $count, string $source): string => $source.'='.$count)->implode(', ');
            $this->line(sprintf('BRAND "%s" %d products (%s)', $brand, $group->count(), $sources));

            foreach ($group->take(max(0, (int) $this->option('samples'))) as $assignment) {
                $this->line('    '.Str::limit($assignment['product'], 100));
            }
        }
    }

    private function rollback(string $backupPath, StorefrontCache $cache): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);

        $removed = DB::transaction(fn (): int => collect($backup['attached'])->sum(fn (array $row): int => DB::table('product_attribute_value')
            ->where('product_id', $row['product_id'])
            ->where('attribute_value_id', $row['value_id'])
            ->delete()));

        $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

        $this->info('REMOVED='.$removed.' OF '.count($backup['attached']));
        $this->info('ASSIGN_MISSING_BRANDS_ROLLBACK=PASS');

        return self::SUCCESS;
    }
}
