<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Illuminate\Support\Facades\Http;
use Throwable;

final class ArmedicalHttpClient
{
    private int $timeoutSeconds = 20;

    private int $maxAttempts = 3;

    private int $retryDelayMilliseconds = 1500;

    private int $requestDelayMilliseconds = 500;

    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    public function withMaxAttempts(int $attempts, int $retryDelayMilliseconds = 1500): self
    {
        $this->maxAttempts = max(1, $attempts);
        $this->retryDelayMilliseconds = max(0, $retryDelayMilliseconds);

        return $this;
    }

    public function withRequestDelayMilliseconds(int $milliseconds): self
    {
        $this->requestDelayMilliseconds = max(0, $milliseconds);

        return $this;
    }

    /**
     * @return array{body: string|null, error: string|null, status: int|null}
     */
    public function fetch(string $url): array
    {
        $lastError = null;
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            if ($this->requestDelayMilliseconds > 0) {
                usleep($this->requestDelayMilliseconds * 1000);
            }

            try {
                $response = Http::connectTimeout(min(5, $this->timeoutSeconds))
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; KonjiShop-ArmedicalCrawler/1.0; +https://konji.pl)',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.5',
                        'Cache-Control' => 'no-cache',
                    ])
                    ->get($url);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();

                if ($attempt < $this->maxAttempts) {
                    $this->pauseBeforeRetry();
                }

                continue;
            }

            $lastStatus = $response->status();

            if ($response->successful()) {
                return [
                    'body' => $response->body(),
                    'error' => null,
                    'status' => $lastStatus,
                ];
            }

            $lastError = 'HTTP '.$lastStatus;

            if (! in_array($lastStatus, [408, 425, 429, 500, 502, 503, 504], true) || $attempt >= $this->maxAttempts) {
                break;
            }

            $this->pauseBeforeRetry();
        }

        return [
            'body' => null,
            'error' => $lastError ?? 'Unknown HTTP error',
            'status' => $lastStatus,
        ];
    }

    private function pauseBeforeRetry(): void
    {
        if ($this->retryDelayMilliseconds > 0) {
            usleep($this->retryDelayMilliseconds * 1000);
        }
    }
}
