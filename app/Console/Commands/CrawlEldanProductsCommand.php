<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Eldan\EldanProductUrlScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class CrawlEldanProductsCommand extends Command
{
    protected $signature = 'eldan:crawl
        {--category=* : Eldan category URL to crawl. Defaults to the main Eldan shop categories.}
        {--max-depth=3 : Number of link levels to follow from the starting categories.}
        {--max-pages=500 : Maximum number of category/product pages to fetch while discovering URLs.}
        {--limit= : Stop after discovering this many product URLs.}
        {--import : Import discovered products by calling eldan:inspect --import.}
        {--delay=500 : Delay in milliseconds between product imports.}
        {--show-failures : Print failed discovery URLs.}';

    protected $description = 'Scrape Eldan category pages, discover product URLs, and optionally import them.';

    public function __construct(
        private readonly EldanProductUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $categories = $this->option('category') ?: EldanProductUrlScraper::DEFAULT_CATEGORY_URLS;
        $maxDepth = max(0, (int) $this->option('max-depth'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $delayMs = max(0, (int) $this->option('delay'));
        $shouldImport = (bool) $this->option('import');

        $this->info('Discovering Eldan product URLs...');

        $result = $this->scraper->scrape(
            startUrls: $categories,
            maxDepth: $maxDepth,
            maxPages: $maxPages,
            limit: $limit,
        );

        $productUrls = $result['product_urls'];

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered product URLs: '.count($productUrls));

        if ($productUrls === []) {
            $this->warn('No Eldan product URLs were discovered. Try increasing --max-depth/--max-pages or pass a more specific --category URL.');

            return self::FAILURE;
        }

        foreach ($productUrls as $productUrl) {
            $this->line($productUrl);
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed discovery URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        if (! $shouldImport) {
            $this->newLine();
            $this->comment('Run again with --import to call eldan:inspect --import for each discovered product.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Importing discovered Eldan products...');

        $failedImports = [];

        foreach ($productUrls as $index => $productUrl) {
            $this->line(sprintf('[%d/%d] %s', $index + 1, count($productUrls), $productUrl));

            try {
                $exitCode = Artisan::call('eldan:inspect', [
                    'url' => $productUrl,
                    '--import' => true,
                    '--no-dump' => true,
                    '--no-interaction' => true,
                ]);

                $output = trim(Artisan::output());

                if ($output !== '') {
                    $this->line($output);
                }

                if ($exitCode !== self::SUCCESS) {
                    $failedImports[$productUrl] = 'eldan:inspect exited with code '.$exitCode;
                }
            } catch (Throwable $exception) {
                $failedImports[$productUrl] = $exception->getMessage();
            }

            if ($delayMs > 0 && $index < count($productUrls) - 1) {
                usleep($delayMs * 1000);
            }
        }

        if ($failedImports !== []) {
            $this->newLine();
            $this->error('Some Eldan products failed to import:');

            foreach ($failedImports as $url => $reason) {
                $this->line($url.' - '.$reason);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All discovered Eldan products were imported.');

        return self::SUCCESS;
    }
}
