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
     * @var list<string>
     */
    private const SUPPORTED_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/gif',
    ];

    /**
     * @param  array<int, string>|null  $allowedHosts
     * @param  array{minimum_file_size_bytes?: int, minimum_dimension_px?: int}  $requirements
     * @param  array{
     *     timeout_seconds?: int,
     *     retry_attempts?: int,
     *     retry_delay_ms?: int,
     *     verify_tls?: bool,
     *     headers?: array<string, string>
     * }  $requestOptions
     */
    public function import(
        string $url,
        string $directory,
        string $disk = 'public',
        ?array $allowedHosts = null,
        array $requirements = [],
        array $requestOptions = [],
    ): array {
        $sourceUrl = $url;
        $url = $this->normalizeUrl($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid image URL [{$sourceUrl}]");
        }

        $host = parse_url($url, PHP_URL_HOST);

        $allowedHosts ??= ['eldan.pl'];

        if (! is_string($host) || ! $this->isAllowedHost($host, $allowedHosts)) {
            throw new RuntimeException("Disallowed image host [{$url}]");
        }

        $timeoutSeconds = max(1, (int) ($requestOptions['timeout_seconds'] ?? 20));
        $retryAttempts = max(1, (int) ($requestOptions['retry_attempts'] ?? 2));
        $retryDelayMs = max(0, (int) ($requestOptions['retry_delay_ms'] ?? 500));
        $verifyTls = (bool) ($requestOptions['verify_tls'] ?? true);
        $headers = [
            'User-Agent' => 'KonjiShopBot/1.0',
            'Accept' => 'image/*',
        ];

        foreach (($requestOptions['headers'] ?? []) as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $request = Http::timeout($timeoutSeconds)
            ->retry($retryAttempts, $retryDelayMs)
            ->withHeaders($headers);

        if (! $verifyTls) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download image [{$url}]");
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new RuntimeException("Downloaded image is empty [{$url}]");
        }

        $mimeType = $this->resolveImageMimeType(
            contentType: $response->header('Content-Type'),
            contents: $contents,
            url: $url,
        );

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

    private function resolveImageMimeType(?string $contentType, string $contents, string $url): string
    {
        $declaredMimeType = $this->normalizeMimeType($contentType);

        if ($declaredMimeType !== null && in_array($declaredMimeType, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            return $declaredMimeType;
        }

        $detectedMimeType = $this->detectImageMimeType($contents);

        if ($detectedMimeType !== null) {
            return $detectedMimeType;
        }

        if ($declaredMimeType !== null && str_starts_with($declaredMimeType, 'image/')) {
            throw new RuntimeException("Unsupported image MIME type [{$declaredMimeType}] for [{$url}]");
        }

        throw new RuntimeException("Response is not an image [{$url}]");
    }

    private function normalizeMimeType(?string $contentType): ?string
    {
        if (! is_string($contentType)) {
            return null;
        }

        $mimeType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mimeType !== '' ? $mimeType : null;
    }

    private function detectImageMimeType(string $contents): ?string
    {
        $imageSize = @getimagesizefromstring($contents);
        $imageSizeMimeType = is_array($imageSize)
            ? $this->normalizeMimeType($imageSize['mime'] ?? null)
            : null;

        if ($imageSizeMimeType !== null && in_array($imageSizeMimeType, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            return $imageSizeMimeType;
        }

        if (str_starts_with($contents, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a')) {
            return 'image/gif';
        }

        if (strlen($contents) >= 12 && substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (strlen($contents) >= 16 && substr($contents, 4, 4) === 'ftyp') {
            $fileTypeBox = substr($contents, 8, 32);

            if (str_contains($fileTypeBox, 'avif') || str_contains($fileTypeBox, 'avis')) {
                return 'image/avif';
            }
        }

        return null;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $url;
        }

        $normalized = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $normalized .= ':'.(int) $parts['port'];
        }

        $normalized .= $this->encodeUrlComponent(
            (string) ($parts['path'] ?? ''),
            "-._~!$&'()*+,;=:@/",
        );

        if (array_key_exists('query', $parts)) {
            $normalized .= '?'.$this->encodeUrlComponent(
                (string) $parts['query'],
                "-._~!$&'()*+,;=:@/?",
            );
        }

        if (array_key_exists('fragment', $parts)) {
            $normalized .= '#'.$this->encodeUrlComponent(
                (string) $parts['fragment'],
                "-._~!$&'()*+,;=:@/?",
            );
        }

        return $normalized;
    }

    private function encodeUrlComponent(string $value, string $safeCharacters): string
    {
        $encoded = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if (
                $character === '%'
                && $index + 2 < $length
                && ctype_xdigit($value[$index + 1].$value[$index + 2])
            ) {
                $encoded .= substr($value, $index, 3);
                $index += 2;

                continue;
            }

            $ordinal = ord($character);
            $isAsciiAlphaNumeric = ($ordinal >= 48 && $ordinal <= 57)
                || ($ordinal >= 65 && $ordinal <= 90)
                || ($ordinal >= 97 && $ordinal <= 122);

            if ($isAsciiAlphaNumeric || str_contains($safeCharacters, $character)) {
                $encoded .= $character;

                continue;
            }

            $encoded .= sprintf('%%%02X', $ordinal);
        }

        return $encoded;
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
