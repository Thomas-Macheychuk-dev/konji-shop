<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Peruka\PerukaProductScraper;
use Illuminate\Console\Command;

final class ScrapePerukaProductCommand extends Command
{
    protected $signature = 'peruka:product
        {url : Peruka product URL to inspect.}
        {--json : Print the extracted product data as JSON.}
        {--save= : Save the extracted product data as JSON under storage/app.}
        {--request-delay-ms=500 : Milliseconds to pause before the Peruka HTTP request.}';

    protected $description = 'Scrape one Peruka product page and print normalized product data without importing it.';

    public function __construct(
        private readonly PerukaProductScraper $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = (string) $this->argument('url');

        $this->scraper
            ->withRequestDelayMilliseconds($this->requestDelayMilliseconds())
            ->withProgressCallback(function (string $message): void {
                $this->line($message);
            });

        $product = $this->scraper->scrape($url);

        $this->info('Scraped Peruka product: '.($product['name'] ?? $url));
        $this->line('External product ID: '.(($product['external_product_id'] ?? null) ?: 'not found'));
        $this->line('Variant product URLs: '.count($product['variant_product_urls'] ?? []));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (is_string($this->option('save')) && trim((string) $this->option('save')) !== '') {
            $this->saveJson((string) $this->option('save'), $product);
        }

        return self::SUCCESS;
    }

    private function requestDelayMilliseconds(): int
    {
        $value = $this->option('request-delay-ms');

        if (! is_string($value) || trim($value) === '') {
            return 500;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function saveJson(string $relativePath, array $result): void
    {
        $relativePath = ltrim($relativePath, '/');
        $path = storage_path('app/'.$relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Saved Peruka product data to storage/app/'.$relativePath);
    }
}
