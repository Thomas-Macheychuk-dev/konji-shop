<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OldShop\OldShopScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads products of one brand from the old ortezka.pl shop into a JSON file for oldshop:import. Read-only.
 */
final class CrawlOldShopProductsCommand extends Command
{
    protected $signature = 'oldshop:crawl
        {--search= : Search phrase on the old shop, e.g. "THUASNE".}
        {--brand= : Keep only products of this brand (case-insensitive). Defaults to the search phrase.}
        {--url=* : Read these product URLs instead of searching.}
        {--save= : Where to save under storage/app/private (default scrapers/oldshop/<search>.json).}
        {--fresh : Ignore products saved by an earlier run and read everything again.}
        {--request-delay-ms=1500 : Pause before each request; the old shop blocks fast crawls for a while.}';

    protected $description = 'Read products of one brand from the old ortezka.pl shop (names, prices per size/colour, descriptions, images) into a JSON file.';

    public function handle(OldShopScraper $scraper): int
    {
        $search = trim((string) $this->option('search'));
        $urls = array_values(array_filter(array_map(fn ($url): ?string => $scraper->productUrl((string) $url), (array) $this->option('url'))));

        if ($search === '' && $urls === []) {
            $this->error('Give --search= or --url=.');

            return self::FAILURE;
        }

        $scraper
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')))
            ->withProgressCallback(fn (string $message): null => $this->line($message));

        if ($urls === []) {
            $urls = $scraper->searchProductUrls($search);
        }

        $brand = Str::lower(trim((string) ($this->option('brand') ?: $search)));
        $path = (string) ($this->option('save') ?: 'scrapers/oldshop/'.(Str::slug($search) ?: 'products').'.json');

        // A run stopped by a deploy, a dropped session or the shop's rate limit continues from the saved file;
        // products and other-brand pages already read are skipped, failures are retried.
        $saved = ! $this->option('fresh') && Storage::disk('local')->exists($path)
            ? json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR)
            : [];
        $products = [];

        foreach ((array) ($saved['products'] ?? []) as $product) {
            $products[$product['source_url']] = $product;
        }

        $otherBrand = (array) ($saved['other_brand'] ?? []);
        $failed = [];

        if ($products !== [] || $otherBrand !== []) {
            $this->line(sprintf('RESUMED PRODUCTS=%d OTHER_BRAND=%d', count($products), count($otherBrand)));
        }

        foreach ($urls as $index => $url) {
            if (isset($products[$url]) || isset($otherBrand[$url])) {
                continue;
            }

            try {
                $product = $scraper->scrape($url);
                unset($failed[$url]);

                if ($brand !== '' && Str::lower((string) $product['brand']) !== $brand) {
                    $otherBrand[$url] = (string) $product['brand'];
                    $this->line(sprintf('[%d/%d] other brand %s: %s', $index + 1, count($urls), $product['brand'] ?? '-', $product['name']));
                } else {
                    $products[$url] = $product;
                    $this->line(sprintf('[%d/%d] %s: %d combinations', $index + 1, count($urls), $product['name'], count($product['combinations'])));
                }
            } catch (Throwable $exception) {
                $failed[$url] = $exception->getMessage();
                $this->warn(sprintf('[%d/%d] FAILED %s %s', $index + 1, count($urls), $url, $exception->getMessage()));
            }

            $this->save($path, $search, $brand, $products, $otherBrand, $failed);
        }

        $this->save($path, $search, $brand, $products, $otherBrand, $failed);
        $this->info(sprintf('PRODUCTS=%d OTHER_BRAND=%d FAILED=%d', count($products), count($otherBrand), count($failed)));
        $this->info('SAVED='.$path);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, string>  $otherBrand
     * @param  array<string, string>  $failed
     */
    private function save(string $path, string $search, string $brand, array $products, array $otherBrand, array $failed): void
    {
        Storage::disk('local')->put($path, json_encode([
            'source' => 'ortezka-old',
            'search' => $search,
            'brand' => $brand,
            'crawled_at' => now()->toIso8601String(),
            'failed' => $failed,
            'other_brand' => $otherBrand,
            'products' => array_values($products),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
