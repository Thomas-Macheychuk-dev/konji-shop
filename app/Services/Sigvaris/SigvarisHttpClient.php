<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

abstract class SigvarisHttpClient
{
    protected const CANONICAL_HOST = 'www.sklep-sigvaris.com';

    protected ?Closure $progressCallback = null;
    protected int $timeoutSeconds = 20;
    protected int $attempts = 3;
    protected int $retryDelayMilliseconds = 1500;
    protected int $requestDelayMilliseconds = 500;
    protected bool $verifyTls = true;

    public function withProgressCallback(?Closure $callback): static
    {
        $this->progressCallback = $callback;
        return $this;
    }

    public function withTimeout(int $seconds): static
    {
        $this->timeoutSeconds = max(1, $seconds);
        return $this;
    }

    public function withAttempts(int $attempts): static
    {
        $this->attempts = max(1, $attempts);
        return $this;
    }

    public function withRetryDelayMilliseconds(int $milliseconds): static
    {
        $this->retryDelayMilliseconds = max(0, $milliseconds);
        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): static
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);
        return $this;
    }

    public function withTlsVerification(bool $verify): static
    {
        $this->verifyTls = $verify;
        return $this;
    }

    /** @param array<string, string> $failedUrls */
    protected function fetchBody(string $url, array &$failedUrls): ?string
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->pause($this->requestDelayMilliseconds);
            $this->emit(sprintf('GET %s%s', $url, $attempt > 1 ? " (attempt {$attempt})" : ''));

            try {
                /** @var Response $response */
                $response = Http::withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                    'Cache-Control' => 'no-cache',
                    'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShopSigvarisCrawler/1.0)',
                ])->withOptions(['verify' => $this->verifyTls])
                    ->timeout($this->timeoutSeconds)
                    ->get($url);

                if ($response->successful()) {
                    return $response->body();
                }

                $lastError = 'HTTP '.$response->status();
                if (! in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true)) {
                    break;
                }
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < $this->attempts) {
                $this->pause($this->retryDelayMilliseconds);
            }
        }

        $failedUrls[$url] = $lastError ?? 'Unknown HTTP failure';
        return null;
    }

    protected function normalizeSiteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (! preg_match('#^https?://#i', $href)) {
            $base = parse_url($baseUrl);
            if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
                return null;
            }

            if (str_starts_with($href, '/')) {
                $href = $base['scheme'].'://'.$base['host'].$href;
            } else {
                $basePath = isset($base['path']) ? dirname($base['path']) : '/';
                $href = $base['scheme'].'://'.$base['host'].'/'.trim($basePath.'/'.$href, '/');
            }
        }

        $parts = parse_url($href);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (! in_array($host, [self::CANONICAL_HOST, 'sklep-sigvaris.com'], true)) {
            return null;
        }

        $path = preg_replace('#/+#', '/', (string) ($parts['path'] ?? '/')) ?: '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://'.self::CANONICAL_HOST.$path.$query;
    }

    protected function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    protected function emit(string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($message);
        }
    }

    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
