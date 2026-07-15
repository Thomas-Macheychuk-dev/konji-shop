<?php

declare(strict_types=1);

namespace App\Services\Images;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RemoteImageImporter
{
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    private const MAX_DIMENSION_PX = 2200;

    private const MAX_DECODED_IMAGE_MEMORY_BYTES = 160 * 1024 * 1024;

    /**
     * @param  array<int, string>|null  $allowedHosts
     * @param  array{minimum_file_size_bytes?: int, minimum_dimension_px?: int}  $requirements
     */
    public function import(
        string $url,
        string $directory,
        string $disk = 'public',
        ?array $allowedHosts = null,
        array $requirements = [],
    ): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid image URL [{$url}]");
        }

        $host = parse_url($url, PHP_URL_HOST);

        $allowedHosts ??= ['eldan.pl'];

        if (! is_string($host) || ! $this->isAllowedHost($host, $allowedHosts)) {
            throw new RuntimeException("Disallowed image host [{$url}]");
        }

        $response = Http::timeout(20)
            ->retry(2, 500)
            ->withHeaders([
                'User-Agent' => 'KonjiShopBot/1.0',
                'Accept' => 'image/*',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download image [{$url}]");
        }

        $mimeType = $response->header('Content-Type');

        if (! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException("Response is not an image [{$url}]");
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new RuntimeException("Downloaded image is empty [{$url}]");
        }

        $this->assertMeetsRequirements($contents, $url, $requirements);

        [$contents, $mimeType] = $this->optimizeIfNeeded($contents, $mimeType, $url);

        $fileSize = strlen($contents);

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new RuntimeException("Image too large [{$url}]");
        }

        $sha256 = hash('sha256', $contents);
        $extension = $this->extensionFromMimeType($mimeType);
        $filename = $sha256.'.'.$extension;
        $path = trim($directory, '/').'/'.$filename;

        if (! Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->put($path, $contents);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'source_url' => $url,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'sha256' => $sha256,
        ];
    }

    /**
     * @param  array{minimum_file_size_bytes?: int, minimum_dimension_px?: int}  $requirements
     */
    private function assertMeetsRequirements(string $contents, string $url, array $requirements): void
    {
        $minimumFileSize = max(0, (int) ($requirements['minimum_file_size_bytes'] ?? 0));

        if ($minimumFileSize > 0 && strlen($contents) < $minimumFileSize) {
            throw new RuntimeException("Image file is smaller than the required minimum [{$url}]");
        }

        $minimumDimension = max(0, (int) ($requirements['minimum_dimension_px'] ?? 0));

        if ($minimumDimension === 0) {
            return;
        }

        $imageSize = @getimagesizefromstring($contents);

        if (! is_array($imageSize)) {
            throw new RuntimeException("Unable to read image dimensions [{$url}]");
        }

        $width = isset($imageSize[0]) ? (int) $imageSize[0] : 0;
        $height = isset($imageSize[1]) ? (int) $imageSize[1] : 0;

        if (min($width, $height) < $minimumDimension) {
            throw new RuntimeException("Image dimensions are smaller than the required minimum [{$url}]");
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function optimizeIfNeeded(string $contents, string $mimeType, string $url): array
    {
        $imageSize = @getimagesizefromstring($contents);

        if (! is_array($imageSize)) {
            return [$contents, $mimeType];
        }

        $width = isset($imageSize[0]) ? (int) $imageSize[0] : 0;
        $height = isset($imageSize[1]) ? (int) $imageSize[1] : 0;

        if (
            strlen($contents) <= self::MAX_FILE_SIZE_BYTES
            && max($width, $height) <= self::MAX_DIMENSION_PX
        ) {
            return [$contents, $mimeType];
        }

        if ($this->estimatedDecodedImageBytes($width, $height) > self::MAX_DECODED_IMAGE_MEMORY_BYTES) {
            throw new RuntimeException("Image dimensions too large to process safely [{$url}]");
        }

        if (! extension_loaded('gd')) {
            return [$contents, $mimeType];
        }

        $sourceImage = @imagecreatefromstring($contents);

        if ($sourceImage === false) {
            return [$contents, $mimeType];
        }

        $bestContents = $contents;
        $bestMimeType = $mimeType;
        $baseScale = max($width, $height) > 0
            ? min(1, self::MAX_DIMENSION_PX / max($width, $height))
            : 1;

        $scaleSteps = [1, 0.9, 0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2];

        try {
            foreach ($scaleSteps as $scaleStep) {
                $targetScale = $baseScale * $scaleStep;
                $targetWidth = max(1, (int) round($width * $targetScale));
                $targetHeight = max(1, (int) round($height * $targetScale));

                $resizedImage = $this->resizeImage($sourceImage, $targetWidth, $targetHeight, $mimeType);

                try {
                    foreach ($this->encodingVariants($mimeType) as [$candidateMimeType, $quality]) {
                        $candidateContents = $this->encodeImage($resizedImage, $candidateMimeType, $quality);

                        if ($candidateContents === null || $candidateContents === '') {
                            continue;
                        }

                        if (strlen($candidateContents) < strlen($bestContents)) {
                            $bestContents = $candidateContents;
                            $bestMimeType = $candidateMimeType;
                        }

                        if (strlen($candidateContents) <= self::MAX_FILE_SIZE_BYTES) {
                            return [$candidateContents, $candidateMimeType];
                        }
                    }
                } finally {
                    if ($resizedImage !== $sourceImage) {
                        imagedestroy($resizedImage);
                    }
                }
            }
        } finally {
            imagedestroy($sourceImage);
        }

        return [$bestContents, $bestMimeType];
    }


    private function estimatedDecodedImageBytes(int $width, int $height): int
    {
        if ($width <= 0 || $height <= 0) {
            return 0;
        }

        return $width * $height * 4;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function encodingVariants(string $mimeType): array
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => [
                ['image/jpeg', 90],
                ['image/jpeg', 82],
                ['image/jpeg', 74],
                ['image/jpeg', 66],
            ],
            'image/png' => [
                ['image/png', 9],
            ],
            'image/webp' => function_exists('imagewebp')
                ? [
                    ['image/webp', 90],
                    ['image/webp', 82],
                    ['image/webp', 74],
                ]
                : [
                    ['image/jpeg', 82],
                ],
            'image/gif' => [
                ['image/png', 9],
            ],
            default => [[$mimeType, 82]],
        };
    }

    /**
     * @param  \GdImage|resource  $sourceImage
     * @return \GdImage|resource
     */
    private function resizeImage($sourceImage, int $targetWidth, int $targetHeight, string $mimeType)
    {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        if ($sourceWidth === $targetWidth && $sourceHeight === $targetHeight) {
            return $sourceImage;
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($targetImage === false) {
            return $sourceImage;
        }

        if (in_array($mimeType, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefill($targetImage, 0, 0, $transparent);
        } else {
            $background = imagecolorallocate($targetImage, 255, 255, 255);
            imagefill($targetImage, 0, 0, $background);
        }

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        return $targetImage;
    }

    /**
     * @param  \GdImage|resource  $image
     */
    private function encodeImage($image, string $mimeType, int $quality): ?string
    {
        ob_start();

        try {
            $successful = match ($mimeType) {
                'image/jpeg', 'image/jpg' => imagejpeg($image, null, $quality),
                'image/png' => imagepng($image, null, max(0, min(9, $quality))),
                'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, $quality) : false,
                'image/gif' => imagegif($image),
                default => false,
            };

            if (! $successful) {
                ob_end_clean();

                return null;
            }

            $contents = ob_get_clean();

            return is_string($contents) ? $contents : null;
        } catch (\Throwable $throwable) {
            ob_end_clean();

            return null;
        }
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    private function isAllowedHost(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = mb_strtolower(ltrim($allowedHost, '.'));
            $host = mb_strtolower($host);

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function extensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            default => throw new RuntimeException("Unsupported image MIME type [{$mimeType}]"),
        };
    }
}
