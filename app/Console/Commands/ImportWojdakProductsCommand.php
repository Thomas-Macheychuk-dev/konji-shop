<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Wojdak\WojdakProductImporter;
use App\Services\Wojdak\WojdakProductNormalizer;
use App\Services\Wojdak\WojdakProductPayloadExtractor;
use App\Services\Wojdak\WojdakProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ImportWojdakProductsCommand extends Command
{
    protected $signature = 'wojdak:import
        {--url=* : Wojdak product URL to import. If passed, category discovery is skipped.}
        {--category=* : Wojdak shop category URL to scan. If omitted, the hard-coded Wojdak shop categories are used.}
        {--root-category=* : Deprecated alias for --category kept for backwards compatibility.}
        {--limit= : Stop after discovering/importing this many product URLs.}
        {--max-pages= : Stop after visiting this many paginated pages per category during URL discovery.}
        {--dry-run : Fetch and normalize products without writing them to the database.}
        {--show-failures : Print failed category/product URLs.}';

    protected $description = 'Import Wojdak shop products as draft products with variants scraped from WooCommerce product pages.';

    public function __construct(
        private readonly WojdakProductUrlScraper $productUrlScraper,
        private readonly WojdakProductPayloadExtractor $extractor,
        private readonly WojdakProductNormalizer $normalizer,
        private readonly WojdakProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $productUrls = $this->option('url') ?: [];
        $failed = [];

        if ($productUrls === []) {
            $categoryUrls = $this->option('category') ?: ($this->option('root-category') ?: []);
            $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;
            $shouldDiscoverCategories = $categoryUrls === [];

            $this->info('Discovering Wojdak product URLs...');

            $discovery = $this->productUrlScraper->scrape(
                categoryUrls: $categoryUrls,
                discoverCategories: $shouldDiscoverCategories,
                limit: $limit,
                maxPages: $maxPages,
            );

            $productUrls = $discovery['product_urls'];
            $failed = array_replace($failed, $discovery['failed_urls']);

            $this->info('Discovered product URLs: '.count($productUrls));
        } elseif ($limit !== null) {
            $productUrls = array_slice($productUrls, 0, $limit);
        }

        if ($productUrls === []) {
            $this->warn('No Wojdak product URLs found.');

            return self::FAILURE;
        }

        $imported = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($productUrls as $productUrl) {
            $this->line('Fetching product: '.$productUrl);

            $response = Http::timeout(30)
                ->withHeaders($this->headers())
                ->get($productUrl);

            if (! $response->successful()) {
                $failed[$productUrl] = 'HTTP '.$response->status();
                $this->warn('Failed: '.$productUrl.' - HTTP '.$response->status());

                continue;
            }

            $payload = $this->extractor->extract($response->body(), $productUrl);
            $normalized = $this->normalizer->normalize($payload);

            $this->line(sprintf(
                'Prepared %s (%s variants)',
                $normalized['name'] ?? $productUrl,
                count($normalized['variants'] ?? [])
            ));

            foreach (($normalized['warnings'] ?? []) as $warning) {
                $this->warn((string) $warning);
            }

            if ($dryRun) {
                continue;
            }

            $product = $this->importer->import($normalized);
            $imported++;

            $this->info('Imported product ID '.$product->id.' with '.$product->variants->count().' variants.');
        }

        if ((bool) $this->option('show-failures') && $failed !== []) {
            $this->newLine();
            $this->warn('Failed Wojdak URLs:');

            foreach ($failed as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if ($dryRun) {
            $this->info('Dry run finished. No products were imported.');
        } else {
            $this->info('Imported products: '.$imported);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
        ];
    }
}
