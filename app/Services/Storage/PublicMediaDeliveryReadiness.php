<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PublicMediaDeliveryReadiness
{
    private const PROBE_PREFIX = 'products/__readiness';

    /**
     * @return list<array{name:string,status:string,message:string}>
     */
    public function checks(): array
    {
        $disk = (array) config('filesystems.disks.public-s3', []);
        $url = trim((string) ($disk['url'] ?? ''));
        $cacheControl = trim((string) ($disk['options']['CacheControl'] ?? ''));

        return [
            $this->check(
                'S3 driver',
                ($disk['driver'] ?? null) === 's3',
                'The public-s3 migration target uses the S3 driver.',
                'filesystems.disks.public-s3.driver must be s3.'
            ),
            $this->check(
                'Private object visibility',
                ($disk['visibility'] ?? null) === 'private',
                'Catalogue objects remain private in S3 and are intended to be read through CloudFront.',
                'PUBLIC_FILESYSTEM_VISIBILITY must be private for the CloudFront OAC production path.'
            ),
            $this->check(
                'S3 bucket',
                trim((string) ($disk['bucket'] ?? '')) !== '',
                'The catalogue S3 bucket is configured.',
                'PUBLIC_FILESYSTEM_BUCKET (or AWS_BUCKET) must be configured.'
            ),
            $this->check(
                'HTTPS CDN URL',
                $this->isCdnUrl($url),
                'PUBLIC_FILESYSTEM_URL is an HTTPS CDN URL rather than a direct S3 endpoint.',
                'PUBLIC_FILESYSTEM_URL must be an HTTPS CloudFront/custom CDN URL, not a direct S3 URL.'
            ),
            $this->check(
                'Shared cache policy',
                $this->hasPositiveSharedCacheTtl($cacheControl),
                'Uploaded catalogue objects include a positive browser/shared cache TTL.',
                'PUBLIC_FILESYSTEM_CACHE_CONTROL must contain a positive max-age or s-maxage directive.'
            ),
        ];
    }

    public function isConfigured(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['status'] !== 'ready') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ready:true,message:string}
     */
    public function probe(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Public media delivery is not configured; resolve the configuration checks before probing.');
        }

        $disk = Storage::disk('public-s3');
        $id = (string) Str::uuid();
        $path = self::PROBE_PREFIX.'/konji-'.$id.'.txt';
        $payload = 'konji-public-media-probe-'.$id;
        $url = $this->publicUrl($path);

        try {
            $written = $disk->put($path, $payload, [
                'ContentType' => 'text/plain; charset=utf-8',
                'CacheControl' => 'no-store,max-age=0',
            ]);

            if (! $written || ! $disk->exists($path)) {
                throw new RuntimeException('The S3 probe object could not be written.');
            }

            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->get($url);

            $this->assertProbeResponse($response, $payload);

            return [
                'ready' => true,
                'message' => 'Private S3 write and CloudFront/CDN read probe succeeded.',
            ];
        } finally {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                // The probe result must reflect the delivery path. A uniquely named
                // probe object can be cleaned manually if the best-effort delete fails.
            }
        }
    }

    private function publicUrl(string $path): string
    {
        $baseUrl = rtrim(trim((string) config('filesystems.disks.public-s3.url')), '/');

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function assertProbeResponse(Response $response, string $payload): void
    {
        if (! $response->successful()) {
            throw new RuntimeException('The CDN probe returned HTTP '.$response->status().'.');
        }

        if ($response->body() !== $payload) {
            throw new RuntimeException('The CDN probe returned unexpected object contents.');
        }
    }

    private function isCdnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.amazonaws.com') || str_contains($host, '.s3.')) {
            return false;
        }

        return true;
    }

    private function hasPositiveSharedCacheTtl(string $cacheControl): bool
    {
        if ($cacheControl === '') {
            return false;
        }

        if (! preg_match('/(?:^|,)\s*(?:s-maxage|max-age)\s*=\s*(\d+)/i', $cacheControl, $matches)) {
            return false;
        }

        return (int) ($matches[1] ?? 0) > 0;
    }

    /**
     * @return array{name:string,status:string,message:string}
     */
    private function check(string $name, bool $ready, string $readyMessage, string $missingMessage): array
    {
        return [
            'name' => $name,
            'status' => $ready ? 'ready' : 'missing',
            'message' => $ready ? $readyMessage : $missingMessage,
        ];
    }
}
