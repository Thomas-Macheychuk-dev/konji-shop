<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisProductUrlScraper;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class DiscoverSigvarisProductLinksCommand extends Command
{
    protected $signature = 'sigvaris:product-links
        {--categories-from=scrapers/sigvaris/categories.json : Category JSON under storage/app.}
        {--category-limit= : Maximum category branches.}
        {--page-limit= : Maximum pages per category.}
        {--timeout=20 : HTTP request timeout.}
        {--attempts=3 : Maximum request attempts.}
        {--retry-delay-ms=2000 : Retry delay.}
        {--request-delay-ms=500 : Request delay.}
        {--insecure : Disable TLS verification.}
        {--no-progress : Hide progress.}
        {--json : Print JSON.}
        {--save= : Save JSON under storage/app.}
        {--show-failures : Print failed URLs.}';

    protected $description = 'Discover paginated sklep-sigvaris.com product URLs and category context without database writes.';

    public function __construct(private readonly SigvarisProductUrlScraper $scraper) { parent::__construct(); }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $this->scraper
            ->withTlsVerification(! (bool) $this->option('insecure'))
            ->withTimeout($this->intOption('timeout', 20, 1))
            ->withAttempts($this->intOption('attempts', 3, 1))
            ->withRetryDelayMilliseconds($this->intOption('retry-delay-ms', 2000, 0))
            ->withRequestDelayMilliseconds($this->intOption('request-delay-ms', 500, 0));
        if (! $json && ! (bool) $this->option('no-progress')) $this->scraper->withProgressCallback(fn (string $m): null => $this->line($m));

        $result = $this->scraper->scrape(
            $this->loadJson((string) $this->option('categories-from')),
            $this->nullablePositive('category-limit'),
            $this->nullablePositive('page-limit'),
        );

        if ($json) $this->line($this->encode($result));
        else {
            $this->info('Sigvaris product-link discovery');
            $this->line('Visited URLs: '.count($result['visited_urls'] ?? []));
            $this->line('Source categories: '.count($result['source_categories'] ?? []));
            $this->line('Product records: '.count($result['products'] ?? []));
            $this->line('Unique product URLs: '.count($result['product_urls'] ?? []));
            $this->line('Failed URLs: '.count($result['failed_urls'] ?? []));
            foreach ($result['category_results'] ?? [] as $category) {
                $this->line('- '.implode(' > ', $category['category_path'] ?? []).' | products='.($category['product_count'] ?? 0).' | pages='.($category['pages_scraped'] ?? 0));
            }
        }
        if ((bool) $this->option('show-failures')) foreach ($result['failed_urls'] ?? [] as $url => $reason) $this->warn($url.' - '.$reason);
        $this->save($result, $json);
        return ($result['product_urls'] ?? []) !== [] ? self::SUCCESS : self::FAILURE;
    }

    private function intOption(string $name, int $default, int $min): int { $v=$this->option($name); return is_numeric($v)?max($min,(int)$v):$default; }
    private function nullablePositive(string $name): ?int { $v=$this->option($name); return $v===null||$v===''?null:max(1,(int)$v); }

    /** @return array<string,mixed> */
    private function loadJson(string $relative): array
    {
        $path=storage_path('app/'.ltrim(trim($relative),'/'));
        if (! is_file($path)) throw new JsonException('Sigvaris category JSON does not exist: '.$path);
        $decoded=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) throw new JsonException('Sigvaris category JSON must contain an object.');
        return $decoded;
    }

    /** @param array<string,mixed> $result */
    private function save(array $result,bool $quiet): void { $relative=trim((string)($this->option('save')??'')); if($relative==='')return; $path=storage_path('app/'.ltrim($relative,'/')); if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true)&&!is_dir(dirname($path)))throw new RuntimeException('Unable to create Sigvaris scraper directory.'); if(file_put_contents($path,$this->encode($result).PHP_EOL)===false)throw new RuntimeException('Unable to save Sigvaris product links.'); if(!$quiet)$this->info('Saved product links to storage/app/'.ltrim($relative,'/')); }
    /** @param array<string,mixed> $data */
    private function encode(array $data): string { $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION); if(!is_string($json))throw new RuntimeException('Unable to encode Sigvaris JSON.'); return $json; }
}
