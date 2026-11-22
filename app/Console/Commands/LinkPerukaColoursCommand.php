<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Peruka\PerukaColourLinks;
use App\Services\Peruka\PerukaProductScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Links the colours of each peruka.pl wig model, so its page offers "Wybierz kolor" like peruka.pl does
 * (owner's feedback, 18 November 2026). Each colour stays its own product and URL; products of one model share
 * a colour_group and show their colour_label.
 *
 * The colours come from peruka.pl's "Wybierz kolor" strip. One page per model is enough: its strip names every
 * other colour. The preview fetches the pages, saves them with CRAWL= and lists the groups; --write --from=
 * applies that crawl without fetching again. Safe to run again after an import.
 */
final class LinkPerukaColoursCommand extends Command
{
    protected $signature = 'peruka:link-colours
        {--from= : A crawl saved by an earlier run (CRAWL=). Otherwise peruka.pl is fetched.}
        {--delay-ms=500 : Pause between peruka.pl requests.}
        {--limit= : Fetch at most this many pages (testing).}
        {--samples=20 : How many groups and failures to list.}
        {--write : Save the colour groups after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the colour groups from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back linking the colours of each peruka.pl wig model.';

    public function __construct(
        private readonly PerukaColourLinks $links,
        private readonly PerukaProductScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $products = Product::query()
            ->where('external_source', 'peruka')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'external_parent_sku', 'colour_group', 'colour_label']);

        $idsByUrl = [];

        foreach ($products as $product) {
            $url = $this->scraper->normalizeProductUrl((string) $product->external_parent_sku);

            if ($url !== null) {
                $idsByUrl[$url] = $product->id;
            }
        }

        $crawl = filled($this->option('from')) ? $this->load((string) $this->option('from')) : $this->crawl(array_keys($idsByUrl));

        if ($crawl === null) {
            return self::FAILURE;
        }

        [$groups, $labels] = $this->groups($crawl['pages'], $idsByUrl);
        $changes = $this->changes($products->keyBy('id')->all(), $groups, $labels);
        $linkedIds = $groups === [] ? [] : array_merge(...array_values($groups));
        $linked = count($linkedIds);
        $noLabel = count(array_filter($linkedIds, fn (int $id): bool => ($labels[$id] ?? null) === null));

        $this->line('=== PERUKA COLOURS ===');
        $this->line(sprintf(
            'PRODUCTS=%d PAGES=%d FAILED=%d GROUPS=%d LINKED=%d NO_LABEL=%d CHANGES=%d',
            $products->count(),
            count($crawl['pages']),
            count($crawl['failed']),
            count($groups),
            $linked,
            $noLabel,
            count($changes),
        ));

        $slugs = $products->pluck('slug', 'id');
        $samples = max(0, (int) $this->option('samples'));

        foreach (array_slice($groups, 0, $samples, true) as $key => $ids) {
            $groupLabels = array_map(fn (int $id): string => $labels[$id] ?? '?', $ids);
            natcasesort($groupLabels);
            $this->line(sprintf('GROUP %s size=%d %s: %s', $key, count($ids), $slugs[$ids[0]], implode(', ', $groupLabels)));
        }

        foreach (array_slice($crawl['failed'], 0, $samples, true) as $url => $message) {
            $this->line('FAILED '.$url.' '.$message);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            return self::SUCCESS;
        }

        $backupPath = 'catalogue-repairs/peruka-colours-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN='.$this->apply($changes, 'old', 'new'));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $urls
     * @return array{pages: array<string, array{label: string|null, siblings: array<string, string|null>}>, failed: array<string, string>}
     */
    private function crawl(array $urls): array
    {
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $delay = max(0, (int) $this->option('delay-ms'));
        $covered = [];
        $pages = [];
        $failed = [];

        foreach ($urls as $url) {
            if (isset($covered[$url])) {
                continue;
            }

            if ($limit !== null && count($pages) + count($failed) >= $limit) {
                break;
            }

            if ($delay > 0 && ($pages !== [] || $failed !== [])) {
                usleep($delay * 1000);
            }

            try {
                $page = $this->links->parse($this->links->fetch($url), $url);
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();

                continue;
            }

            $pages[$url] = $page;
            $covered[$url] = true;

            foreach (array_keys($page['siblings']) as $sibling) {
                $covered[$sibling] = true;
            }

            if ((count($pages) + count($failed)) % 100 === 0) {
                $this->line(sprintf('FETCHED=%d COVERED=%d OF %d', count($pages) + count($failed), count($covered), count($urls)));
            }
        }

        $crawl = ['pages' => $pages, 'failed' => $failed];
        $path = 'catalogue-repairs/peruka-colours-crawl-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($path, json_encode($crawl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('CRAWL='.$path);

        return $crawl;
    }

    /**
     * @return array{pages: array<string, array{label: string|null, siblings: array<string, string|null>}>, failed: array<string, string>}|null
     */
    private function load(string $path): ?array
    {
        if (! Storage::disk('local')->exists($path)) {
            $this->error('CRAWL_NOT_FOUND='.$path);

            return null;
        }

        return json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Products linked by any page's strip form one group. Groups of one product are dropped.
     *
     * @param  array<string, array{label: string|null, siblings: array<string, string|null>}>  $pages
     * @param  array<string, int>  $idsByUrl
     * @return array{0: array<string, list<int>>, 1: array<int, string|null>}
     */
    private function groups(array $pages, array $idsByUrl): array
    {
        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            $parent[$id] ??= $id;

            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        $labels = [];

        foreach ($pages as $url => $page) {
            $id = $idsByUrl[$url] ?? null;

            if ($id === null) {
                continue;
            }

            $find($id);

            foreach ($page['siblings'] as $siblingUrl => $label) {
                $siblingId = $idsByUrl[$siblingUrl] ?? null;

                if ($siblingId === null) {
                    continue;
                }

                $parent[$find($siblingId)] = $find($id);
                $labels[$siblingId] ??= $label;
            }
        }

        foreach ($pages as $url => $page) {
            if (isset($idsByUrl[$url])) {
                $labels[$idsByUrl[$url]] ??= $page['label'];
            }
        }

        $members = [];

        foreach (array_keys($parent) as $id) {
            $members[$find($id)][] = $id;
        }

        $urlsById = array_flip($idsByUrl);
        $groups = [];

        foreach ($members as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            sort($ids);
            $urls = array_map(fn (int $id): string => $urlsById[$id], $ids);
            sort($urls);
            $groups['peruka-'.substr(sha1($urls[0]), 0, 16)] = $ids;
        }

        ksort($groups);

        return [$groups, $labels];
    }

    /**
     * @param  array<int, Product>  $products
     * @param  array<string, list<int>>  $groups
     * @param  array<int, string|null>  $labels
     * @return list<array<string, mixed>>
     */
    private function changes(array $products, array $groups, array $labels): array
    {
        $target = [];

        foreach ($groups as $key => $ids) {
            foreach ($ids as $id) {
                $target[$id] = ['colour_group' => $key, 'colour_label' => isset($labels[$id]) ? mb_substr($labels[$id], 0, 100) : null];
            }
        }

        $changes = [];

        foreach ($products as $id => $product) {
            $old = ['colour_group' => $product->colour_group, 'colour_label' => $product->colour_label];
            $new = $target[$id] ?? ['colour_group' => null, 'colour_label' => null];

            if ($old !== $new) {
                $changes[] = ['id' => $id, 'old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function apply(array $changes, string $from, string $to): int
    {
        $written = 0;

        foreach ($changes as $change) {
            $product = Product::query()->find($change['id']);

            if ($product === null || $product->colour_group !== $change[$from]['colour_group'] || $product->colour_label !== $change[$from]['colour_label']) {
                $this->warn('SKIPPED_CHANGED_SINCE product='.$change['id']);

                continue;
            }

            // Saved through the model so storefront cache invalidation observers run.
            $product->colour_group = $change[$to]['colour_group'];
            $product->colour_label = $change[$to]['colour_label'];
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
        $this->info('RESTORED='.$this->apply($backup['changes'], 'new', 'old').' OF '.count($backup['changes']));

        return self::SUCCESS;
    }
}
