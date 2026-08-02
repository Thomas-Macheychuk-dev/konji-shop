<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Microlife\MicrolifeCategoryUrlScraper;
use Illuminate\Console\Command;

final class DiscoverMicrolifeCategoriesCommand extends Command
{
    protected $signature = 'microlife:categories
        {--catalogue=all : Catalogue branch: all, consumer, or professional.}
        {--url=* : Explicit Microlife catalogue root. Overrides --catalogue.}
        {--json : Print the discovery result as JSON.}
        {--save= : Save the discovery result as JSON under storage/app.}
        {--show-failures : Print failed Microlife URLs.}
        {--insecure : Disable TLS certificate verification for Microlife requests.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Maximum attempts per Microlife page.}
        {--retry-delay-ms=2000 : Milliseconds to pause before retrying a failed request.}
        {--request-delay-ms=500 : Milliseconds to pause before each Microlife HTTP request.}';

    protected $description = 'Discover consumer and professional Microlife product categories.';

    public function __construct(
        private readonly MicrolifeCategoryUrlScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $catalogue = $this->catalogueOption();

        if ($catalogue === null) {
            return self::FAILURE;
        }

        $urls = $this->option('url');

        if ($urls === []) {
            $urls = match ($catalogue) {
                'consumer' => [MicrolifeCategoryUrlScraper::CONSUMER_URL],
                'professional' => [MicrolifeCategoryUrlScraper::PROFESSIONAL_URL],
                default => MicrolifeCategoryUrlScraper::DEFAULT_URLS,
            };
        }

        $insecure = (bool) $this->option('insecure');

        $this->scraper
            ->withTlsVerification(! $insecure)
            ->withTimeout($this->integerOption('timeout', 20, 1))
            ->withAttempts($this->integerOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->integerOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->integerOption('request-delay-ms', 500, 0))
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $this->info('Discovering Microlife product categories...');

        if ($insecure) {
            $this->warn('TLS certificate verification is disabled for this Microlife run.');
        }

        $result = $this->scraper->scrape($urls);

        $this->info('Visited pages: '.count($result['visited_urls']));
        $this->info('Discovered category URLs: '.count($result['category_urls']));
        $this->info('Product-scraping category URLs: '.count($result['product_category_urls']));

        foreach ($result['catalogues'] as $catalogueResult) {
            $this->line(sprintf(
                '- %s: %d categories, %d product categories',
                ucfirst((string) $catalogueResult['catalogue_type']),
                (int) $catalogueResult['category_count'],
                (int) $catalogueResult['product_category_count'],
            ));
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            foreach ($result['categories'] as $category) {
                $suffix = (bool) $category['is_product_category'] ? 'product category' : 'parent category';
                $this->line(sprintf(
                    '[%s] %s [%s] - %s',
                    $category['catalogue_type'],
                    implode(' > ', $category['path']),
                    $suffix,
                    $category['url'],
                ));
            }
        }

        if ((bool) $this->option('show-failures') && $result['failed_urls'] !== []) {
            $this->newLine();
            $this->warn('Failed Microlife URLs:');

            foreach ($result['failed_urls'] as $url => $reason) {
                $this->line($url.' - '.$reason);
            }
        }

        $savePath = $this->option('save');

        if (is_string($savePath) && trim($savePath) !== '') {
            $this->saveJson($savePath, $result);
        }

        return $result['product_category_urls'] === [] ? self::FAILURE : self::SUCCESS;
    }

    private function catalogueOption(): ?string
    {
        $catalogue = mb_strtolower(trim((string) $this->option('catalogue')));

        if (in_array($catalogue, ['all', 'consumer', 'professional'], true)) {
            return $catalogue;
        }

        $this->error('Invalid --catalogue value. Use all, consumer, or professional.');

        return null;
    }

    private function integerOption(string $name, int $default, int $minimum): int
    {
        $value = $this->option($name);

        return is_numeric($value)
            ? max($minimum, (int) $value)
            : $default;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim(trim($relativePath), '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ).PHP_EOL,
        );

        $this->info('Saved discovery result to storage/app/'.$relativePath);
    }
}
