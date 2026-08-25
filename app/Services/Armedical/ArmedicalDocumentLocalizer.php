<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class ArmedicalDocumentLocalizer
{
    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'armedical.pl',
        'www.armedical.pl',
    ];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /**
     * @param  array<int, mixed>  $documents
     * @return array{
     *     resources:list<array{source_url:string,label:string,type:string,href:string,path:string}>,
     *     created:int,
     *     reused:int,
     *     failed:int,
     *     failures:list<string>,
     *     complete:bool
     * }
     */
    public function localize(
        array $documents,
        string $externalId,
        ?string $existingDescription,
        bool $downloadMissing,
        bool $refresh,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
    ): array {
        $resources = [];
        $created = 0;
        $reused = 0;
        $failures = [];
        $seen = [];
        $requestIndex = 0;

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            try {
                $sourceUrl = $this->validatedSourceUrl($document['source_url'] ?? null);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
                continue;
            }

            if (isset($seen[$sourceUrl])) {
                continue;
            }

            $seen[$sourceUrl] = true;
            $label = $this->stringOrNull($document['label'] ?? null) ?: 'Dokument producenta';
            $type = $this->stringOrNull($document['type'] ?? null) ?: 'document';
            $preserved = $this->preservedResource($existingDescription, $externalId, $sourceUrl);

            if (! $refresh && $preserved !== null && Storage::disk('public')->exists($preserved['path'])) {
                $resources[] = [
                    'source_url' => $sourceUrl,
                    'label' => $label,
                    'type' => $type,
                    'href' => $preserved['href'],
                    'path' => $preserved['path'],
                ];
                $reused++;
                continue;
            }

            if (! $downloadMissing) {
                continue;
            }

            if ($requestIndex > 0 && $requestDelayMs > 0) {
                usleep($requestDelayMs * 1000);
            }
            $requestIndex++;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/octet-stream,*/*;q=0.8',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.7',
                    'Referer' => 'https://armedical.pl/',
                ])
                    ->timeout(max(1, $timeoutSeconds))
                    ->retry(max(1, $attempts), max(0, $retryDelayMs))
                    ->get($sourceUrl);

                if (! $response->successful()) {
                    throw new InvalidArgumentException('ARmedical document returned HTTP '.$response->status().': '.$sourceUrl);
                }

                $body = $response->body();

                if ($body === '') {
                    throw new InvalidArgumentException('ARmedical document response is empty: '.$sourceUrl);
                }

                $extension = $this->extension(
                    body: $body,
                    contentType: (string) $response->header('Content-Type'),
                    sourceUrl: $sourceUrl,
                );
                $path = 'products/armedical/'.$externalId.'/documents/'.hash('sha256', $body).'.'.$extension;
                $alreadyStored = Storage::disk('public')->exists($path);

                if (! $alreadyStored && ! Storage::disk('public')->put($path, $body)) {
                    throw new InvalidArgumentException('Unable to store ARmedical document: '.$path);
                }

                $alreadyStored ? $reused++ : $created++;
                $resources[] = [
                    'source_url' => $sourceUrl,
                    'label' => $label,
                    'type' => $type,
                    'href' => '/storage/'.$path,
                    'path' => $path,
                ];
            } catch (Throwable $exception) {
                $failures[] = $sourceUrl.' — '.$exception->getMessage();
            }
        }

        return [
            'resources' => $resources,
            'created' => $created,
            'reused' => $reused,
            'failed' => count($failures),
            'failures' => $failures,
            'complete' => $failures === [] && count($resources) === count($seen),
        ];
    }

    private function validatedSourceUrl(mixed $value): string
    {
        $url = $this->stringOrNull($value);

        if ($url === null) {
            throw new InvalidArgumentException('Mapped ARmedical document is missing its source URL.');
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidArgumentException('Unapproved ARmedical document URL: '.$url);
        }

        if (! str_starts_with($path, '/wp-content/uploads/')) {
            throw new InvalidArgumentException('ARmedical document URL is outside the approved uploads path: '.$url);
        }

        return $url;
    }

    /** @return array{href:string,path:string}|null */
    private function preservedResource(?string $description, string $externalId, string $sourceUrl): ?array
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        preg_match_all(
            '#<a\b[^>]*data-armedical-document-source=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>#isu',
            $description,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $candidateSource = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($candidateSource !== $sourceUrl) {
                continue;
            }

            $prefix = '/storage/products/armedical/'.$externalId.'/documents/';

            if (! str_starts_with($href, $prefix)) {
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

    private function extension(string $body, string $contentType, string $sourceUrl): string
    {
        $mime = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
        $urlPath = strtolower((string) parse_url($sourceUrl, PHP_URL_PATH));

        return match (true) {
            str_starts_with($body, '%PDF'), str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'wordprocessingml'), str_ends_with($urlPath, '.docx') => 'docx',
            str_contains($mime, 'msword'), str_ends_with($urlPath, '.doc') => 'doc',
            default => throw new InvalidArgumentException('Unsupported ARmedical document content type: '.($mime ?: 'unknown').' ['.$sourceUrl.']'),
        };
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
