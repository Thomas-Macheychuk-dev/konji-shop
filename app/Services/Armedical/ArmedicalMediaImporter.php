<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class ArmedicalMediaImporter
{
    private const SOURCE = 'armedical';

    /** @var list<string> */
    private const IMAGE_ALLOWED_HOSTS = [
        'armedical.pl',
        'www.armedical.pl',
    ];

    private const IMAGE_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
        private readonly ArmedicalDocumentLocalizer $documentLocalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $mapped
     * @return array{product:Product,warnings:list<string>,stats:array<string,int>}
     */
    public function import(
        array $mapped,
        bool $importImages = true,
        bool $importDocuments = true,
        ?int $imageLimit = null,
        bool $refreshImages = false,
        bool $refreshDocuments = false,
        int $timeoutSeconds = 30,
        int $attempts = 5,
        int $retryDelayMs = 3000,
        int $requestDelayMs = 250,
    ): array {
        $this->warnings = [];
        $this->stats = $this->emptyStats();

        $productData = is_array($mapped['product'] ?? null) ? $mapped['product'] : [];
        $externalId = $this->requiredString($productData['external_id'] ?? null, 'product external ID');

        if (($mapped['source'] ?? null) !== self::SOURCE || ($productData['external_source'] ?? null) !== self::SOURCE) {
            throw new InvalidArgumentException('Mapped product source must be armedical.');
        }

        $product = Product::query()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        if (! $product instanceof Product) {
            throw new InvalidArgumentException('ARmedical media import requires an existing local product row: '.$externalId);
        }

        if ($product->status !== ProductStatus::DRAFT) {
            throw new InvalidArgumentException('ARmedical media import only operates on draft products.');
        }

        if ($importImages) {
            $this->syncImages(
                product: $product,
                mapped: $mapped,
                imageLimit: $imageLimit,
                refreshImages: $refreshImages,
                timeoutSeconds: max(1, $timeoutSeconds),
                attempts: max(1, $attempts),
                retryDelayMs: max(0, $retryDelayMs),
                requestDelayMs: max(0, $requestDelayMs),
            );
        }

        if ($importDocuments) {
            $documents = $this->documentLocalizer->localize(
                documents: is_array($mapped['documents'] ?? null) ? array_values($mapped['documents']) : [],
                externalId: $externalId,
                existingDescription: $product->description,
                downloadMissing: true,
                refresh: $refreshDocuments,
                timeoutSeconds: max(1, $timeoutSeconds),
                attempts: max(1, $attempts),
                retryDelayMs: max(0, $retryDelayMs),
                requestDelayMs: max(0, $requestDelayMs),
            );

            $this->stats['documents_created'] += $documents['created'];
            $this->stats['documents_reused'] += $documents['reused'];
            $this->stats['documents_failed'] += $documents['failed'];

            foreach ($documents['failures'] as $failure) {
                $this->warnings[] = 'Document skipped: '.$failure;
            }

            if ($documents['complete']) {
                $description = $this->replaceResourceSection($product->description, $documents['resources']);

                if ($description !== $product->description) {
                    $product->update(['description' => $description]);
                    $this->stats['descriptions_updated']++;
                }
            } elseif (($mapped['documents'] ?? []) !== []) {
                $this->warnings[] = 'Not all mapped ARmedical documents were localized; the existing product resource section was preserved.';
            }
        }

        $product = $product->fresh(['images', 'variants']);

        if (! $product instanceof Product) {
            throw new InvalidArgumentException('Unable to reload ARmedical product after media import.');
        }

        return [
            'product' => $product,
            'warnings' => array_values(array_unique($this->warnings)),
            'stats' => $this->stats,
        ];
    }

    /** @param array<string, mixed> $mapped */
    private function syncImages(
        Product $product,
        array $mapped,
        ?int $imageLimit,
        bool $refreshImages,
        int $timeoutSeconds,
        int $attempts,
        int $retryDelayMs,
        int $requestDelayMs,
    ): void {
        $allImages = array_values(array_filter(
            $mapped['images'] ?? [],
            static fn (mixed $image): bool => is_array($image) && is_string($image['source_url'] ?? null),
        ));
        $images = $imageLimit === null ? $allImages : array_slice($allImages, 0, max(0, $imageLimit));
        $fullImageSet = $imageLimit === null || $imageLimit >= count($allImages);

        if ($images === []) {
            return;
        }

        $primaryIndex = 0;
        foreach ($images as $index => $imageData) {
            if (($imageData['is_primary'] ?? false) === true) {
                $primaryIndex = $index;
                break;
            }
        }

        $syncedIds = [];
        $successfulIds = [];
        $primaryId = null;

        foreach ($images as $index => $imageData) {
            if ($index > 0 && $requestDelayMs > 0) {
                usleep($requestDelayMs * 1000);
            }

            $url = $this->validatedImageUrl($imageData['source_url'] ?? null);

            if ($url === null) {
                $this->warnings[] = 'Image skipped because the ARmedical source URL is not approved: '.($this->stringOrNull($imageData['source_url'] ?? null) ?? '[missing URL]');
                $this->stats['images_failed']++;
                continue;
            }

            $existing = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('source_url', $url)
                ->first();
            $canReuse = ! $refreshImages
                && $existing !== null
                && $existing->path !== ''
                && Storage::disk($existing->disk)->exists($existing->path);

            if ($canReuse) {
                $existing->update([
                    'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                    'sort_order' => $index,
                    'is_main' => false,
                ]);
                $syncedIds[] = $existing->id;
                $successfulIds[] = $existing->id;
                if ($index === $primaryIndex) {
                    $primaryId = $existing->id;
                }
                $this->stats['images_reused']++;
                continue;
            }

            $oldDisk = $existing?->disk;
            $oldPath = $existing?->path;

            try {
                $imported = $this->remoteImageImporter->import(
                    url: $url,
                    directory: 'products/armedical/'.$product->external_id.'/gallery',
                    disk: 'public',
                    allowedHosts: self::IMAGE_ALLOWED_HOSTS,
                    requestOptions: [
                        'timeout_seconds' => $timeoutSeconds,
                        'retry_attempts' => $attempts,
                        'retry_delay_ms' => $retryDelayMs,
                        'headers' => $this->imageRequestHeaders($mapped),
                    ],
                );
            } catch (Throwable $exception) {
                $this->warnings[] = 'Image skipped: '.$url.' — '.$exception->getMessage();
                $this->stats['images_failed']++;
                continue;
            }

            $image = ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'source_url' => $url,
                ],
                [
                    'disk' => $imported['disk'],
                    'path' => $imported['path'],
                    'mime_type' => $imported['mime_type'],
                    'file_size' => $imported['file_size'],
                    'sha256' => $imported['sha256'],
                    'alt_text' => $this->stringOrNull($imageData['alt'] ?? null) ?: $product->name,
                    'title' => null,
                    'sort_order' => $index,
                    'is_main' => false,
                ],
            );

            if (
                $existing !== null
                && is_string($oldDisk)
                && is_string($oldPath)
                && $oldPath !== ''
                && ($oldDisk !== $image->disk || $oldPath !== $image->path)
            ) {
                $this->deleteStoredImageIfUnreferenced($oldDisk, $oldPath, $image->id);
            }

            $syncedIds[] = $image->id;
            $successfulIds[] = $image->id;
            if ($index === $primaryIndex) {
                $primaryId = $image->id;
            }

            if ($existing === null) {
                $this->stats['images_created']++;
            } else {
                $this->stats['images_updated']++;
            }
        }

        if ($successfulIds === []) {
            $this->warnings[] = 'All selected ARmedical image downloads failed; existing images were preserved.';
            return;
        }

        $primaryId ??= $successfulIds[0];
        $productImages = ProductImage::query()
            ->where('product_id', $product->id)
            ->get();
        $hasForeignMain = $productImages
            ->filter(fn (ProductImage $image): bool => ! $this->isArmedicalUrl($image->source_url))
            ->contains(fn (ProductImage $image): bool => $image->is_main);

        $productImages
            ->filter(fn (ProductImage $image): bool => $this->isArmedicalUrl($image->source_url))
            ->each(function (ProductImage $image): void {
                $image->update(['is_main' => false]);
            });

        if (! $hasForeignMain) {
            ProductImage::query()->whereKey($primaryId)->update(['is_main' => true]);
        }

        if (! $fullImageSet || count($syncedIds) !== count($images)) {
            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $syncedIds)
            ->get()
            ->filter(fn (ProductImage $image): bool => $this->isArmedicalUrl($image->source_url))
            ->each(function (ProductImage $image): void {
                if ($image->path !== '') {
                    $this->deleteStoredImageIfUnreferenced($image->disk, $image->path, $image->id);
                }

                $image->delete();
                $this->stats['images_deleted']++;
            });
    }

    /** @param list<array{source_url:string,label:string,type:string,href:string,path:string}> $resources */
    private function replaceResourceSection(?string $description, array $resources): ?string
    {
        $description = $description ?? '';
        $description = preg_replace('#\s*<section\b[^>]*class=["\'][^"\']*\barmedical-resources\b[^"\']*["\'][^>]*>.*?</section>\s*#isu', "\n", $description) ?? $description;
        $description = trim($description);

        if ($resources === []) {
            return $description !== '' ? $description : null;
        }

        $items = [];
        foreach ($resources as $resource) {
            $items[] = '<li><a data-armedical-document-source="'.e($resource['source_url']).'" data-armedical-document-type="'.e($resource['type']).'" href="'.e($resource['href']).'" target="_blank" rel="noopener noreferrer">'.e($resource['label']).'</a></li>';
        }

        $section = '<section class="armedical-resources"><h2>Materiały producenta</h2><ul>'.implode('', $items).'</ul></section>';

        return $description !== '' ? $description."\n".$section : $section;
    }

    private function validatedImageUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if ($url === null) {
            return null;
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/[\x00-\x20\x7F]/u', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || ! in_array($host, self::IMAGE_ALLOWED_HOSTS, true)) {
            return null;
        }

        return str_starts_with($path, '/wp-content/uploads/') ? $url : null;
    }

    private function isArmedicalUrl(?string $url): bool
    {
        return $this->validatedImageUrl($url) !== null;
    }

    private function deleteStoredImageIfUnreferenced(string $disk, string $path, int $exceptImageId): void
    {
        $isReferenced = ProductImage::query()
            ->where('disk', $disk)
            ->where('path', $path)
            ->whereKeyNot($exceptImageId)
            ->exists();

        if (! $isReferenced && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** @param array<string, mixed> $mapped @return array<string, string> */
    private function imageRequestHeaders(array $mapped): array
    {
        $referer = $this->stringOrNull($mapped['canonical_url'] ?? null)
            ?: $this->stringOrNull($mapped['source_url'] ?? null)
                ?: 'https://armedical.pl/';

        return [
            'User-Agent' => self::IMAGE_BROWSER_USER_AGENT,
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en-US;q=0.7,en;q=0.6',
            'Referer' => $referer,
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => 'image',
            'Sec-Fetch-Mode' => 'no-cors',
            'Sec-Fetch-Site' => 'same-origin',
        ];
    }

    /** @return array<string, int> */
    private function emptyStats(): array
    {
        return [
            'images_created' => 0,
            'images_updated' => 0,
            'images_reused' => 0,
            'images_deleted' => 0,
            'images_failed' => 0,
            'documents_created' => 0,
            'documents_reused' => 0,
            'documents_failed' => 0,
            'descriptions_updated' => 0,
        ];
    }

    private function requiredString(mixed $value, string $label): string
    {
        $string = $this->stringOrNull($value);

        if ($string === null) {
            throw new InvalidArgumentException('Mapped ARmedical '.$label.' is missing.');
        }

        return $string;
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
