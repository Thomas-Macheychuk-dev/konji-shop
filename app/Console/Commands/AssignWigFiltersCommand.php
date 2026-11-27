<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Peruka\PerukaFilterCrawler;
use App\Services\Peruka\PerukaProductScraper;
use App\Services\Storefront\StorefrontCache;
use App\Services\TwojaPeruka\TwojaPerukaProductUrlScraper;
use App\Services\Wigs\WigFilterAttributes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Gives wigs the attributes the listing filters offer, as on peruka.pl (owner, 27 November 2026). See
 * WigFilterAttributes for what comes from where.
 *
 * The preview fetches peruka.pl's filtered lists and twojaperuka.pl's subcategories, saves them (CRAWL=) and lists
 * what would change; --write --from= applies that crawl without fetching again. A product's wig attributes are
 * replaced as a set; other attributes stay. Products the crawl does not list keep what they have. Run it again
 * after an import, like peruka:link-colours.
 */
final class AssignWigFiltersCommand extends Command
{
    private const BACKUP_PREFIX = 'catalogue-repairs/wig-filters-';

    protected $signature = 'wigs:filters
        {--from= : A crawl saved by an earlier run (CRAWL=). Otherwise the shops are fetched.}
        {--crawl=catalogue-repairs/wig-filters-crawl.json : Where a new crawl is saved.}
        {--only= : peruka or twojaperuka; both by default.}
        {--delay-ms=500 : Pause between requests.}
        {--samples=20 : How many changes and unmatched pages to list.}
        {--write : Save the attributes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the attributes from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back the wig filter attributes (length, colour, base, hair type…).';

    public function __construct(
        private readonly PerukaFilterCrawler $perukaCrawler,
        private readonly PerukaProductScraper $perukaScraper,
        private readonly TwojaPerukaProductUrlScraper $twojaPerukaScraper,
        private readonly WigFilterAttributes $attributes,
        private readonly StorefrontCache $cache,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $sources = match ((string) $this->option('only')) {
            '' => ['peruka', 'twojaperuka'],
            'peruka', 'twojaperuka' => [(string) $this->option('only')],
            default => null,
        };

        if ($sources === null) {
            $this->error('ONLY must be peruka or twojaperuka');

            return self::FAILURE;
        }

        $crawl = filled($this->option('from')) ? $this->load((string) $this->option('from')) : $this->crawl($sources);

        if ($crawl === null) {
            return self::FAILURE;
        }

        $report = ['unmatched' => [], 'unlabelled' => [], 'unknown_filters' => [], 'from_name' => 0];
        $wanted = [];

        if (in_array('peruka', $sources, true) && isset($crawl['peruka'])) {
            $wanted += $this->perukaAttributes($crawl['peruka'], $report);
        }

        if (in_array('twojaperuka', $sources, true) && isset($crawl['twojaperuka'])) {
            $wanted += $this->twojaPerukaAttributes($crawl['twojaperuka'], $report);
        }

        $current = $this->currentAttributes(array_keys($wanted));
        $changes = [];
        $perValue = [];

        foreach ($wanted as $productId => $labels) {
            foreach ($labels as $slug => $values) {
                foreach (array_keys($values) as $label) {
                    $perValue[$slug][$label] = ($perValue[$slug][$label] ?? 0) + 1;
                }
            }

            if ($this->normalised($labels) !== $this->normalised($current[$productId] ?? [])) {
                $changes[$productId] = $labels;
            }
        }

        $this->line('=== WIG FILTERS ===');
        $this->line(sprintf(
            'PRODUCTS=%d CHANGES=%d COLOUR_FROM_NAME=%d UNMATCHED_URLS=%d UNLABELLED_VALUES=%d FAILED=%d',
            count($wanted),
            count($changes),
            $report['from_name'],
            count($report['unmatched']),
            count($report['unlabelled']),
            count($crawl['failed'] ?? []),
        ));

        foreach (WigFilterAttributes::ATTRIBUTES as $slug => $definition) {
            if (! isset($perValue[$slug])) {
                continue;
            }

            arsort($perValue[$slug]);
            $this->line($definition['name'].': '.implode(', ', array_map(
                fn (string $label, int $count): string => $label.'='.$count,
                array_keys($perValue[$slug]),
                $perValue[$slug],
            )));
        }

        $samples = max(0, (int) $this->option('samples'));
        $slugs = Product::query()->whereKey(array_slice(array_keys($changes), 0, $samples))->pluck('slug', 'id');

        foreach (array_slice($changes, 0, $samples, true) as $productId => $labels) {
            $this->line(sprintf('SET product=%d %s: %s', $productId, $slugs[$productId] ?? '', $this->describe($labels)));
        }

        foreach (array_slice($report['unmatched'], 0, $samples) as $url) {
            $this->line('UNMATCHED '.$url);
        }

        foreach ($report['unlabelled'] as $value) {
            $this->line('UNLABELLED '.$value);
        }

        foreach (array_keys($report['unknown_filters']) as $filter) {
            $this->line('UNKNOWN_FILTER '.$filter);
        }

        foreach (array_slice($crawl['failed'] ?? [], 0, $samples, true) as $key => $message) {
            $this->line('FAILED '.$key.' '.$message);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            return self::SUCCESS;
        }

        $this->write($changes);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $sources
     * @return array{peruka?: array<string, mixed>, twojaperuka?: array<string, list<string>>, failed: array<string, string>}
     */
    private function crawl(array $sources): array
    {
        $crawl = ['failed' => []];
        $progress = fn (string $message) => $this->line($message);

        if (in_array('peruka', $sources, true)) {
            $this->perukaCrawler->withDelay((int) $this->option('delay-ms'))->withProgress($progress);
            $crawl['peruka'] = [];

            foreach (PerukaFilterCrawler::DEFAULT_CATEGORIES as $slug) {
                $this->line('peruka.pl '.$slug);

                try {
                    $result = $this->perukaCrawler->crawlCategory($slug, array_keys(WigFilterAttributes::PERUKA_FILTERS));
                } catch (RuntimeException $exception) {
                    $crawl['failed']['peruka '.$slug] = $exception->getMessage();

                    continue;
                }

                $crawl['peruka'][$slug] = $result;
                $crawl['failed'] += array_combine(
                    array_map(fn (string $key): string => 'peruka '.$key, array_keys($result['failed'])),
                    $result['failed'],
                ) ?: [];
            }
        }

        if (in_array('twojaperuka', $sources, true)) {
            $this->line('twojaperuka.pl subcategories');
            $result = $this->twojaPerukaScraper
                ->withRequestDelayMilliseconds((int) $this->option('delay-ms'))
                ->scrapeCategories(array_map(fn (string $path): string => 'https://twojaperuka.pl'.$path, array_keys(WigFilterAttributes::TWOJAPERUKA_CATEGORIES)));
            $crawl['twojaperuka'] = [];

            foreach ($result['category_results'] as $category) {
                $crawl['twojaperuka'][(string) parse_url($category['url'], PHP_URL_PATH)] = $category['product_urls'];
            }

            foreach ($result['failed_urls'] as $url => $message) {
                $crawl['failed']['twojaperuka '.$url] = $message;
            }
        }

        $path = (string) $this->option('crawl');
        Storage::disk('local')->put($path, json_encode($crawl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('CRAWL='.$path);

        return $crawl;
    }

    /** @return array<string, mixed>|null */
    private function load(string $path): ?array
    {
        if (! Storage::disk('local')->exists($path)) {
            $this->error('CRAWL_NOT_FOUND='.$path);

            return null;
        }

        return json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Product id => attribute slug => label => sort position.
     *
     * @param  array<string, array<string, mixed>>  $categories
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, array<string, int>>>
     */
    private function perukaAttributes(array $categories, array &$report): array
    {
        $products = Product::query()
            ->where('external_source', 'peruka')
            ->get(['id', 'name', 'external_parent_sku', 'colour_group']);
        $idsByUrl = [];
        $groups = [];

        foreach ($products as $product) {
            $url = $this->perukaScraper->normalizeProductUrl((string) $product->external_parent_sku);

            if ($url !== null) {
                $idsByUrl[$url] = $product->id;
            }

            if (filled($product->colour_group)) {
                $groups[$product->colour_group][] = $product->id;
            }
        }

        $groupOf = $products->pluck('colour_group', 'id');
        $wanted = [];

        foreach ($categories as $slug => $category) {
            foreach ($category['filters'] ?? [] as $filterId => $filter) {
                $attribute = WigFilterAttributes::PERUKA_FILTERS[(int) $filterId] ?? null;

                if ($attribute === null) {
                    $report['unknown_filters'][$slug.' '.$filterId] = true;

                    continue;
                }

                foreach ($filter['values'] as $valueId => $value) {
                    if (! is_array($value['urls'] ?? null)) {
                        continue;
                    }

                    $label = WigFilterAttributes::PERUKA_PICTURE_LABELS[(int) $filterId][(int) $valueId] ?? $value['label'];

                    if (! filled($label)) {
                        $report['unlabelled'][] = $slug.' '.$filterId.'/'.$valueId;

                        continue;
                    }

                    foreach ($value['urls'] as $url) {
                        $id = $idsByUrl[$url] ?? null;

                        if ($id === null) {
                            $report['unmatched'][$url] = $url;

                            continue;
                        }

                        $group = $groupOf[$id] ?? null;
                        $targets = in_array($attribute, WigFilterAttributes::PER_COLOUR, true) || ! filled($group) ? [$id] : $groups[$group];

                        foreach ($targets as $target) {
                            $wanted[$target][$attribute][(string) $label] = (int) $value['position'];
                        }
                    }
                }
            }
        }

        // peruka.pl's colour filter lists one colour per model; the names tell the colour of the others.
        $names = $products->pluck('name', 'id');

        foreach (array_keys($wanted) as $id) {
            if (! isset($wanted[$id][WigFilterAttributes::COLOUR])) {
                $colour = WigFilterAttributes::colourFromName((string) $names[$id]);

                if ($colour !== null) {
                    $wanted[$id][WigFilterAttributes::COLOUR] = [$colour => 0];
                    $report['from_name']++;
                }
            }
        }

        $report['unmatched'] = array_values($report['unmatched']);

        return $wanted;
    }

    /**
     * @param  array<string, list<string>>  $categories  subcategory path => product URLs
     * @param  array<string, mixed>  $report
     * @return array<int, array<string, array<string, int>>>
     */
    private function twojaPerukaAttributes(array $categories, array &$report): array
    {
        $products = Product::query()
            ->where('external_source', 'twojaperuka')
            ->get(['id', 'name', 'external_parent_sku']);
        $idsByUrl = [];

        foreach ($products as $product) {
            $idsByUrl[$this->twojaPerukaUrl((string) $product->external_parent_sku)] = $product->id;
        }

        $wanted = [];
        $categoryColour = [];

        foreach ($categories as $path => $urls) {
            [$attribute, $label] = WigFilterAttributes::TWOJAPERUKA_CATEGORIES[$path] ?? [null, null];

            if ($attribute === null) {
                continue;
            }

            foreach ($urls as $url) {
                $id = $idsByUrl[$this->twojaPerukaUrl($url)] ?? null;

                if ($id === null) {
                    $report['unmatched'][] = $url;

                    continue;
                }

                if ($attribute === WigFilterAttributes::COLOUR) {
                    $categoryColour[$id] ??= $label;

                    continue;
                }

                $wanted[$id][$attribute][$label] = 0;
            }
        }

        foreach ($products as $product) {
            $colour = WigFilterAttributes::colourFromName((string) $product->name) ?? ($categoryColour[$product->id] ?? null);

            if ($colour !== null && (isset($wanted[$product->id]) || isset($categoryColour[$product->id]))) {
                $wanted[$product->id][WigFilterAttributes::COLOUR] = [$colour => 0];
            }
        }

        return $wanted;
    }

    private function twojaPerukaUrl(string $url): string
    {
        $path = rtrim((string) parse_url(trim($url), PHP_URL_PATH), '/');

        return mb_strtolower(rawurldecode($path));
    }

    /**
     * The wig attributes products have now: product id => attribute slug => label => 0.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, array<string, int>>>
     */
    private function currentAttributes(array $productIds): array
    {
        $current = [];

        foreach (array_chunk($productIds, 1000) as $chunk) {
            $rows = DB::table('product_attribute_value')
                ->join('attribute_values', 'attribute_values.id', '=', 'product_attribute_value.attribute_value_id')
                ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
                ->whereIn('attributes.slug', array_keys(WigFilterAttributes::ATTRIBUTES))
                ->whereIn('product_attribute_value.product_id', $chunk)
                ->get(['product_attribute_value.product_id', 'attributes.slug', 'attribute_values.value']);

            foreach ($rows as $row) {
                $current[(int) $row->product_id][(string) $row->slug][(string) $row->value] = 0;
            }
        }

        return $current;
    }

    /**
     * @param  array<string, array<string, int>>  $labels
     * @return array<string, list<string>>
     */
    private function normalised(array $labels): array
    {
        $normalised = [];

        foreach ($labels as $slug => $values) {
            $names = array_map(strval(...), array_keys($values));
            sort($names);
            $normalised[$slug] = $names;
        }

        ksort($normalised);

        return $normalised;
    }

    /** @param  array<string, array<string, int>>  $labels */
    private function describe(array $labels): string
    {
        $parts = [];

        foreach ($this->normalised($labels) as $slug => $names) {
            $parts[] = WigFilterAttributes::ATTRIBUTES[$slug]['name'].'='.implode('|', $names);
        }

        return implode('; ', $parts);
    }

    /** @param  array<int, array<string, array<string, int>>>  $changes */
    private function write(array $changes): void
    {
        $valueIds = [];
        $attributes = [];
        $backup = [];
        $old = $this->managedValueIds(array_keys($changes));

        foreach ($changes as $productId => $labels) {
            $ids = [];

            foreach ($labels as $slug => $values) {
                $attributes[$slug] ??= $this->attributes->attribute($slug);

                foreach ($values as $label => $position) {
                    $ids[] = $valueIds[$slug][$label] ??= $this->attributes->value($attributes[$slug], (string) $label, $position)->id;
                }
            }

            sort($ids);
            $backup[] = ['id' => $productId, 'old' => $old[$productId] ?? [], 'new' => $ids];
        }

        $backupPath = self::BACKUP_PREFIX.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $backup,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->info('BACKUP='.$backupPath);

        $this->info('WRITTEN='.$this->apply($backup, 'old', 'new'));
    }

    /**
     * @param  list<array{id: int, old: list<int>, new: list<int>}>  $changes
     */
    private function apply(array $changes, string $from, string $to): int
    {
        $written = 0;

        foreach (array_chunk($changes, 500) as $chunk) {
            $products = Product::query()->whereKey(array_column($chunk, 'id'))->get()->keyBy('id');
            $current = $this->managedValueIds(array_column($chunk, 'id'));

            foreach ($chunk as $change) {
                $product = $products->get($change['id']);

                if ($product === null || ($current[$product->id] ?? []) !== $change[$from]) {
                    $this->warn('SKIPPED_CHANGED_SINCE product='.$change['id']);

                    continue;
                }

                DB::transaction(function () use ($product, $change, $from, $to): void {
                    $product->attributeValues()->detach($change[$from]);
                    $product->attributeValues()->attach($change[$to]);
                });

                $written++;
            }
        }

        // Attribute pivots do not fire product observers.
        $this->cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

        return $written;
    }

    /**
     * The wig attribute value ids of products, sorted.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<int>>
     */
    private function managedValueIds(array $productIds): array
    {
        $ids = [];

        foreach (array_chunk($productIds, 1000) as $chunk) {
            $rows = DB::table('product_attribute_value')
                ->join('attribute_values', 'attribute_values.id', '=', 'product_attribute_value.attribute_value_id')
                ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
                ->whereIn('attributes.slug', array_keys(WigFilterAttributes::ATTRIBUTES))
                ->whereIn('product_attribute_value.product_id', $chunk)
                ->get(['product_attribute_value.product_id', 'attribute_values.id']);

            foreach ($rows as $row) {
                $ids[(int) $row->product_id][] = (int) $row->id;
            }
        }

        foreach ($ids as &$list) {
            sort($list);
        }
        unset($list);

        return $ids;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $this->info('RESTORED='.$this->apply($backup['changes'], 'new', 'old').' OF '.count($backup['changes']));

        if (str_starts_with($backupPath, self::BACKUP_PREFIX)) {
            Storage::disk('local')->move($backupPath, $backupPath.'.rolled-back');
        }

        return self::SUCCESS;
    }
}
