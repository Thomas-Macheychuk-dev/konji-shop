<?php

declare(strict_types=1);

namespace App\Services\Medi;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\RemoteImageImporter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class MediImageRepairService
{
    private const MINIMUM_FILE_SIZE_BYTES = 5 * 1024;

    private const MINIMUM_DIMENSION_PX = 300;

    /**
     * @var list<string>
     */
    private const IMAGE_ALLOWED_HOSTS = [
        's7e5a.scene7.com',
        'www.medi-polska.pl',
    ];

    public function __construct(
        private readonly RemoteImageImporter $remoteImageImporter,
    ) {}

    /**
     * @param  array<string, mixed>  $scraped
     * @return array{
     *     product_id: int,
     *     external_id: string,
     *     name: string,
     *     source_images: int,
     *     valid_before: int,
     *     invalid_detected: int,
     *     invalid_removed: int,
     *     images_imported: int,
     *     valid_after: int,
     *     unresolved: list<array{source_url: string, attempts: list<array{url: string, error: string}>}>,
     *     warnings: list<string>,
     *     has_usable_image: bool
     * }
     */
    public function repair(
        array $scraped,
        bool $dryRun = false,
        ?int $imageLimit = 5,
        bool $removeInvalid = true,
    ): array {
        $externalId = $this->externalId($scraped);
        $product = Product::query()
            ->where('external_source', 'medi')
            ->where('external_id', $externalId)
            ->with('images')
            ->first();

        if (! $product instanceof Product) {
            throw new RuntimeException("Medi product [{$externalId}] was not found in the database");
        }

        $sourceImages = $this->sourceImages($scraped);
        $invalidImages = $product->images
            ->filter(fn (ProductImage $image): bool => ! $this->isUsable($image))
            ->values();
        $validImages = $product->images
            ->reject(fn (ProductImage $image): bool => $invalidImages->contains('id', $image->id))
            ->values();
        $validBefore = $validImages->count();
        $invalidRemoved = 0;
        $imagesImported = 0;
        $warnings = [];
        $unresolved = [];

        if (! $dryRun && $removeInvalid) {
            foreach ($invalidImages as $invalidImage) {
                $this->deleteImage($invalidImage);
                $invalidRemoved++;
            }

            $product->load('images');
            $validImages = $product->images
                ->filter(fn (ProductImage $image): bool => $this->isUsable($image))
                ->values();
        }

        $maximumImages = $imageLimit !== null && $imageLimit > 0 ? $imageLimit : null;
        $coveredUrls = [];

        foreach ($validImages as $image) {
            if (is_string($image->source_url) && $image->source_url !== '') {
                $coveredUrls[$image->source_url] = true;
            }
        }

        foreach ($sourceImages as $sourceImage) {
            if ($maximumImages !== null && $validImages->count() + $imagesImported >= $maximumImages) {
                break;
            }

            $sourceUrl = $sourceImage['url'];
            $candidateUrls = $this->candidateUrls($sourceUrl);
            $isCovered = false;

            foreach ($candidateUrls as $candidateUrl) {
                if (isset($coveredUrls[$candidateUrl])) {
                    $isCovered = true;
                    break;
                }
            }

            if ($isCovered) {
                continue;
            }

            if ($dryRun) {
                $unresolved[] = [
                    'source_url' => $sourceUrl,
                    'attempts' => [],
                ];

                continue;
            }

            $attempts = [];
            $imported = null;

            foreach ($candidateUrls as $candidateUrl) {
                try {
                    $imported = $this->remoteImageImporter->import(
                        $candidateUrl,
                        'products/medi/'.$product->external_id.'/gallery',
                        'public',
                        self::IMAGE_ALLOWED_HOSTS,
                        [
                            'minimum_file_size_bytes' => self::MINIMUM_FILE_SIZE_BYTES,
                            'minimum_dimension_px' => self::MINIMUM_DIMENSION_PX,
                        ],
                    );
                    break;
                } catch (Throwable $exception) {
                    $attempts[] = [
                        'url' => $candidateUrl,
                        'error' => $exception->getMessage(),
                    ];
                }
            }

            if (! is_array($imported)) {
                $unresolved[] = [
                    'source_url' => $sourceUrl,
                    'attempts' => $attempts,
                ];

                continue;
            }

            $existingBySha = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('sha256', $imported['sha256'])
                ->first();

            if ($existingBySha instanceof ProductImage) {
                $coveredUrls[$sourceUrl] = true;
                $coveredUrls[$imported['source_url']] = true;
                $warnings[] = 'Duplicate image content skipped for '.$sourceUrl;

                continue;
            }

            $alt = $sourceImage['alt'] ?: $product->name;

            ProductImage::query()->create([
                'product_id' => $product->id,
                'disk' => $imported['disk'],
                'path' => $imported['path'],
                'source_url' => $imported['source_url'],
                'mime_type' => $imported['mime_type'],
                'file_size' => $imported['file_size'],
                'sha256' => $imported['sha256'],
                'alt_text' => $alt,
                'title' => $alt,
                'sort_order' => $validImages->count() + $imagesImported,
                'is_main' => $validImages->count() + $imagesImported === 0,
            ]);

            $imagesImported++;
            $coveredUrls[$sourceUrl] = true;
            $coveredUrls[$imported['source_url']] = true;
        }

        if (! $dryRun) {
            $this->normalizeImageOrder($product);
            $product->load('images');
        }

        $validAfter = $dryRun
            ? $validImages->count()
            : $product->images->filter(fn (ProductImage $image): bool => $this->isUsable($image))->count();

        return [
            'product_id' => (int) $product->id,
            'external_id' => (string) $product->external_id,
            'name' => (string) $product->name,
            'source_images' => count($sourceImages),
            'valid_before' => $validBefore,
            'invalid_detected' => $invalidImages->count(),
            'invalid_removed' => $invalidRemoved,
            'images_imported' => $imagesImported,
            'valid_after' => $validAfter,
            'unresolved' => $unresolved,
            'warnings' => $warnings,
            'has_usable_image' => $validAfter > 0,
        ];
    }

    /**
     * @return list<string>
     */
    public function candidateUrls(string $url): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return [$url];
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || mb_strtolower($host) !== 's7e5a.scene7.com') {
            return [$url];
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($scheme) || ! is_string($path)) {
            return [$url];
        }

        $baseUrl = $scheme.'://'.$host.$path;
        $assetUrl = str_ends_with($baseUrl, ':2-to-3')
            ? substr($baseUrl, 0, -strlen(':2-to-3'))
            : $baseUrl;

        return array_values(array_unique([
            $url,
            $assetUrl.'?$Product-medical-2to3$',
            $assetUrl.'?wid=1200&hei=1800&fit=constrain,1&fmt=jpeg',
            $assetUrl,
        ]));
    }

    private function isUsable(ProductImage $image): bool
    {
        $disk = Storage::disk($image->disk);

        if (! $disk->exists($image->path)) {
            return false;
        }

        $contents = $disk->get($image->path);

        if (strlen($contents) < self::MINIMUM_FILE_SIZE_BYTES) {
            return false;
        }

        $size = @getimagesizefromstring($contents);

        if (! is_array($size)) {
            return false;
        }

        $width = isset($size[0]) ? (int) $size[0] : 0;
        $height = isset($size[1]) ? (int) $size[1] : 0;

        return min($width, $height) >= self::MINIMUM_DIMENSION_PX;
    }

    private function deleteImage(ProductImage $image): void
    {
        $hasOtherReference = ProductImage::query()
            ->where('disk', $image->disk)
            ->where('path', $image->path)
            ->whereKeyNot($image->id)
            ->exists();

        if (! $hasOtherReference) {
            Storage::disk($image->disk)->delete($image->path);
        }

        $image->delete();
    }

    private function normalizeImageOrder(Product $product): void
    {
        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (ProductImage $image): int => $this->isUsable($image) ? 0 : 1)
            ->values();

        foreach ($images as $index => $image) {
            $image->update([
                'sort_order' => $index,
                'is_main' => $index === 0,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     * @return list<array{url: string, alt: string|null}>
     */
    private function sourceImages(array $scraped): array
    {
        $images = [];
        $seen = [];

        foreach (($scraped['images'] ?? []) as $image) {
            if (! is_array($image)) {
                continue;
            }

            $url = is_string($image['url'] ?? null) ? trim($image['url']) : '';

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $images[] = [
                'url' => $url,
                'alt' => is_string($image['alt'] ?? null) && trim($image['alt']) !== ''
                    ? trim($image['alt'])
                    : null,
            ];
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function externalId(array $scraped): string
    {
        $externalId = $scraped['external_product_id'] ?? null;

        if (is_int($externalId)) {
            return (string) $externalId;
        }

        if (is_string($externalId) && trim($externalId) !== '') {
            return trim($externalId);
        }

        throw new RuntimeException('Medi image repair payload is missing external_product_id');
    }
}
