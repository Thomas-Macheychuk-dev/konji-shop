<?php

declare(strict_types=1);

namespace App\Services\Sigvaris;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class SigvarisGpsrDocumentLocalizer
{
    private const DOWNLOAD_PATH = '/module/prestadogpsrmanager/download';

    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'sklep-sigvaris.com',
        'www.sklep-sigvaris.com',
    ];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /**
     * @param  array<int, mixed>  $downloads
     * @return array{resources:list<array{source_url:string,label:string,href:string,path:string}>,created:int,reused:int}
     */
    public function localize(
        array $downloads,
        string $externalId,
        ?string $existingDescription,
        bool $downloadMissing,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
    ): array {
        $resources = [];
        $created = 0;
        $reused = 0;
        $seen = [];

        foreach ($downloads as $download) {
            if (! is_array($download)) {
                continue;
            }

            $sourceUrl = $this->validatedSourceUrl($download['source_url'] ?? null);
            $label = $this->stringOrNull($download['label'] ?? null) ?: 'Instrukcja / dokument PDF';

            if (isset($seen[$sourceUrl])) {
                continue;
            }

            $seen[$sourceUrl] = true;
            $preserved = $this->preservedResource($existingDescription, $externalId, $label);

            if ($preserved !== null && Storage::disk('public')->exists($preserved['path'])) {
                $resources[] = [
                    'source_url' => $sourceUrl,
                    'label' => $label,
                    'href' => $preserved['href'],
                    'path' => $preserved['path'],
                ];
                $reused++;
                continue;
            }

            if (! $downloadMissing) {
                continue;
            }

            if ($requestDelayMs > 0) {
                usleep($requestDelayMs * 1000);
            }

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/pdf,application/octet-stream,image/png,image/jpeg,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,*/*',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                'Referer' => 'https://www.sklep-sigvaris.com/',
            ])
                ->timeout(max(1, $timeoutSeconds))
                ->retry(max(1, $attempts), max(0, $retryDelayMs))
                ->get($sourceUrl);

            if (! $response->successful()) {
                throw new RuntimeException('Sigvaris GPSR document returned HTTP '.$response->status().': '.$sourceUrl);
            }

            $body = $response->body();

            if ($body === '') {
                throw new RuntimeException('Sigvaris GPSR document response is empty: '.$sourceUrl);
            }

            $extension = $this->extension($body, (string) $response->header('Content-Type'));
            $path = 'products/sigvaris/'.$externalId.'/documents/'.hash('sha256', $body).'.'.$extension;
            $exists = Storage::disk('public')->exists($path);

            if (! $exists && ! Storage::disk('public')->put($path, $body)) {
                throw new RuntimeException('Unable to store Sigvaris GPSR document: '.$path);
            }

            $exists ? $reused++ : $created++;
            $resources[] = [
                'source_url' => $sourceUrl,
                'label' => $label,
                'href' => '/storage/'.$path,
                'path' => $path,
            ];
        }

        return [
            'resources' => $resources,
            'created' => $created,
            'reused' => $reused,
        ];
    }

    private function validatedSourceUrl(mixed $value): string
    {
        $url = $this->stringOrNull($value);

        if ($url === null) {
            throw new InvalidArgumentException('Mapped Sigvaris download is missing its source URL.');
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true) || $path !== self::DOWNLOAD_PATH) {
            throw new InvalidArgumentException('Unapproved Sigvaris GPSR document URL: '.$url);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $attachmentId = (string) ($query['id_attachment'] ?? '');
        $productId = (string) ($query['id_product'] ?? '');

        if (! ctype_digit($attachmentId) || ! ctype_digit($productId)) {
            throw new InvalidArgumentException('Invalid Sigvaris GPSR document parameters: '.$url);
        }

        // PrestaDog's id_product is a request parameter, not a stable Konji
        // product identity. Product/document association comes from the
        // SHA-pinned approved Sigvaris map entry containing this source URL.

        return $url;
    }

    /** @return array{href:string,path:string}|null */
    private function preservedResource(?string $description, string $externalId, string $label): ?array
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        preg_match_all(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#isu',
            $description,
            $matches,
            PREG_SET_ORDER,
        );

        $expectedLabel = $this->normalizeLabel($label);
        $prefix = '/storage/products/sigvaris/'.$externalId.'/documents/';

        foreach ($matches as $match) {
            $href = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $anchorLabel = $this->normalizeLabel((string) ($match[2] ?? ''));

            if ($anchorLabel !== $expectedLabel || ! str_starts_with($href, $prefix)) {
                continue;
            }

            $path = parse_url($href, PHP_URL_PATH);

            if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
                continue;
            }

            return [
                'href' => $href,
                'path' => substr($path, strlen('/storage/')),
            ];
        }

        return null;
    }

    private function extension(string $body, string $contentType): string
    {
        $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match (true) {
            str_starts_with($body, '%PDF'), str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'wordprocessingml') => 'docx',
            str_contains($mime, 'msword') => 'doc',
            default => throw new RuntimeException('Unsupported Sigvaris GPSR document content type: '.($mime ?: 'unknown')),
        };
    }

    private function normalizeLabel(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
