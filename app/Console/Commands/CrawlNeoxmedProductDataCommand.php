<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Neoxmed\NeoxmedProductDataCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class CrawlNeoxmedProductDataCommand extends Command
{
    protected $signature = 'neoxmed:crawl-product-data
        {--from=scrapers/neoxmed/categories.json : Category discovery JSON file under storage/app.}
        {--category=* : Explicit NeoxMed category URL instead of reading --from.}
        {--discover : Discover categories from neoxmed.com before crawling; ignores --from.}
        {--limit= : Maximum number of deduplicated products in the final output.}
        {--offset=0 : Number of deduplicated products to skip in the final output.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts for HTTP 429 and 5xx responses.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a NeoxMed request.}
        {--request-delay-ms=750 : Milliseconds to pause before each NeoxMed HTTP request.}
        {--no-progress : Do not print per-category progress.}
        {--json : Print full product data as JSON.}
        {--save= : Save full product data JSON under storage/app.}
        {--show-failures : Print failed NeoxMed category URLs.}';

    protected $description = 'Scrape NeoxMed product sections from its static catalogue category pages.';

    public function __construct(
        private readonly NeoxmedProductDataCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $this->crawler
            ->withTimeout(max(1, (int) $this->option('timeout')))
            ->withMaxAttempts(max(1, (int) $this->option('attempts')), max(0, (int) $this->option('retry-delay-ms')))
            ->withRequestDelayMilliseconds(max(0, (int) $this->option('request-delay-ms')));

        if (! $json && ! (bool) $this->option('no-progress')) {
            $this->crawler->withProgressCallback(fn (string $message): null => $this->line($message));
        }

        $explicitCategories = array_values(array_map('strval', $this->option('category')));

        if ($explicitCategories !== []) {
            if (! $json) {
                $this->info('Scraping NeoxMed product data from explicit category URLs...');
            }

            $result = $this->crawler->crawlCategoryUrls($explicitCategories, $this->limit(), $this->offset());
        } elseif ((bool) $this->option('discover')) {
            if (! $json) {
                $this->info('Discovering and scraping NeoxMed catalogue pages...');
            }

            $result = $this->crawler->crawl($this->limit(), $this->offset());
        } else {
            if (! $json) {
                $this->info('Scraping NeoxMed product data from saved category discovery JSON...');
            }

            $result = $this->crawler->crawlFromCategoryDiscovery(
                $this->loadCategoryDiscovery((string) $this->option('from')),
                $this->limit(),
                $this->offset(),
            );
        }

        if ($json) {
            $this->line($this->encodeJson($result));
        } else {
            $this->info('Source category URLs: '.$result['source_category_url_count']);
            $this->info('Visited category pages: '.count($result['visited_urls']));
            $this->info('Discovered unique products: '.$result['discovered_product_count']);
            $this->info('Selected products: '.$result['product_count']);
            $this->info('Cross-category duplicates merged: '.count($result['duplicate_products']));
            $this->info('Warnings: '.count($result['warnings']));
            $this->info('Failed URLs: '.count($result['failed_urls']));

            foreach ($result['products'] as $product) {
                $this->line(sprintf(
                    '- %s | %s | %s | images=%d | size-charts=%d',
                    $product['sku'] ?? 'SKU?',
                    $product['name'] ?? 'Unnamed',
                    implode(' / ', $product['categories'] ?? []),
                    count($product['images'] ?? []),
                    count($product['size_chart_images'] ?? []),
                ));
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed NeoxMed URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result, $json);
        }

        return $result['product_count'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private function offset(): int
    {
        return max(0, (int) $this->option('offset'));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadCategoryDiscovery(string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new JsonException('Unable to read NeoxMed category discovery JSON: storage/app/'.$relativePath);
            }

            return $this->decodeJson($contents, 'storage/app/'.$relativePath);
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            throw new JsonException('NeoxMed category discovery JSON does not exist: storage/app/'.$relativePath);
        }

        return $this->decodeJson(Storage::disk('local')->get($relativePath), 'local disk: '.$relativePath);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $contents, string $source): array
    {
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('NeoxMed JSON file does not contain an object: '.$source);
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function saveJson(string $relativePath, array $result, bool $quiet): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create NeoxMed product-data directory: '.$directory);
        }

        if (file_put_contents($path, $this->encodeJson($result)) === false) {
            throw new RuntimeException('Unable to save NeoxMed product data JSON: '.$path);
        }

        if (! $quiet) {
            $this->info('Saved full product data to storage/app/'.$relativePath);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
