<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use App\Models\Product;
use App\Support\Storage\PublicFilesystemUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class SigvarisSizeChartRepair
{
    private const SOURCE = 'sigvaris';

    /** @var list<string> */
    private const ALLOWED_HOSTS = ['sklep-sigvaris.com', 'www.sklep-sigvaris.com'];

    public function __construct(
        private readonly SigvarisProductScraper $scraper,
    ) {}

    public function configureDiscovery(
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
        bool $verifyTls = true,
    ): self {
        $this->scraper
            ->withTimeout(max(1, $timeoutSeconds))
            ->withAttempts(max(1, $attempts))
            ->withRetryDelayMilliseconds(max(0, $retryDelayMs))
            ->withRequestDelayMilliseconds(max(0, $requestDelayMs))
            ->withTlsVerification($verifyTls);

        return $this;
    }

    /** @return array{url:string,label:string}|null */
    public function discover(string $sourceUrl): ?array
    {
        $source = $this->scraper->scrapeOrFail($sourceUrl);
        $chart = is_array($source['size_chart'] ?? null) ? $source['size_chart'] : null;
        $url = is_array($chart) ? $this->safeSigvarisImageUrl($chart['url'] ?? null) : null;

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => 'TABELA ROZMIARÓW',
        ];
    }

    /** @return array{action:string,path:string,href:string} */
    public function repair(
        Product $product,
        array $chart,
        int $assetTimeoutSeconds = 30,
        int $assetAttempts = 3,
        int $assetRetryDelayMs = 1500,
        bool $verifyTls = true,
    ): array {
        if ($product->external_source !== self::SOURCE || trim((string) $product->external_id) === '') {
            throw new InvalidArgumentException('Size-chart repair only accepts persisted Sigvaris products.');
        }

        $existingPath = $this->existingLocalChartPath((string) $product->description);

        if ($existingPath !== null && Storage::disk('public')->exists($existingPath)) {
            return [
                'action' => 'reused',
                'path' => $existingPath,
                'href' => PublicFilesystemUrl::url($existingPath),
            ];
        }

        $sourceUrl = $this->safeSigvarisImageUrl($chart['url'] ?? null);

        if ($sourceUrl === null) {
            throw new InvalidArgumentException('Invalid Sigvaris size-chart image URL.');
        }

        [$contents, $mime] = $this->downloadImage(
            $sourceUrl,
            max(1, $assetTimeoutSeconds),
            max(1, $assetAttempts),
            max(0, $assetRetryDelayMs),
            $verifyTls,
        );

        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => throw new InvalidArgumentException('Unsupported size-chart image MIME type: '.$mime),
        };
        $sha = hash('sha256', $contents);
        $externalId = trim((string) $product->external_id);
        $path = 'products/sigvaris/'.$externalId.'/size-chart/'.$sha.'.'.$extension;
        $disk = Storage::disk('public');
        $created = ! $disk->exists($path);

        if ($created && ! $disk->put($path, $contents)) {
            throw new InvalidArgumentException('Unable to store Sigvaris size-chart image.');
        }

        $href = PublicFilesystemUrl::url($path);
        $description = $this->injectAnchor((string) $product->description, $href);
        $product->update(['description' => $description]);

        return [
            'action' => $created ? 'created' : 'reused',
            'path' => $path,
            'href' => $href,
        ];
    }

    /** @return array{0:string,1:string} */
    private function downloadImage(string $url, int $timeoutSeconds, int $attempts, int $retryDelayMs, bool $verifyTls): array
    {
        $lastError = 'Unknown image request failure.';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                /** @var Response $response */
                $response = Http::withHeaders([
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                    'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopSigvarisSizeChartRepair/1.0)',
                    'Referer' => 'https://www.sklep-sigvaris.com/',
                ])->withOptions(['verify' => $verifyTls])
                    ->timeout($timeoutSeconds)
                    ->get($url);

                if ($response->successful()) {
                    $contents = $response->body();
                    $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

                    if ($contents === '' || ! str_starts_with($mime, 'image/')) {
                        throw new InvalidArgumentException('Size-chart response is not a non-empty image.');
                    }

                    if (strlen($contents) > 15 * 1024 * 1024) {
                        throw new InvalidArgumentException('Size-chart image exceeds the 15 MiB safety limit.');
                    }

                    return [$contents, $mime];
                }

                $lastError = 'HTTP '.$response->status();
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < $attempts && $retryDelayMs > 0) {
                usleep($retryDelayMs * 1000);
            }
        }

        throw new InvalidArgumentException('Unable to download Sigvaris size chart: '.$lastError);
    }

    private function injectAnchor(string $description, string $href): string
    {
        $anchor = '<a data-sigvaris-size-chart="1" href="'.e($href).'" target="_blank" rel="noopener noreferrer">TABELA ROZMIARÓW</a>';

        if (preg_match('#<a\b(?=[^>]*data-sigvaris-size-chart=["\']1["\'])[^>]*>.*?</a>#isu', $description) === 1) {
            $updated = preg_replace(
                '#<a\b(?=[^>]*data-sigvaris-size-chart=["\']1["\'])[^>]*>.*?</a>#isu',
                $anchor,
                $description,
                1,
            );

            return is_string($updated) ? $updated : $description;
        }

        $updated = preg_replace('/TABELA\s+ROZMIARÓW/ui', $anchor, $description, 1, $count);

        if (is_string($updated) && $count === 1) {
            return $updated;
        }

        $section = '<section class="sigvaris-size-chart"><p>'.$anchor.'</p></section>';

        return trim($description) !== '' ? $description."\n".$section : $section;
    }

    private function existingLocalChartPath(string $description): ?string
    {
        if (preg_match(
            '#<a\b(?=[^>]*data-sigvaris-size-chart=["\']1["\'])[^>]*href=["\']([^"\']+)["\']#isu',
            $description,
            $matches,
        ) !== 1) {
            return null;
        }

        $path = PublicFilesystemUrl::path((string) ($matches[1] ?? ''));

        return $path !== null && str_starts_with($path, 'products/sigvaris/') && str_contains($path, '/size-chart/')
            ? $path
            : null;
    }

    private function safeSigvarisImageUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $path = strtolower((string) parse_url($value, PHP_URL_PATH));

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        if (! str_contains($path, '/img/cms/') || preg_match('/\.(?:png|jpe?g|webp|gif|svg)$/i', $path) !== 1) {
            return null;
        }

        return $value;
    }
}
