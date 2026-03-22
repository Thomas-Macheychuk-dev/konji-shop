<?php

declare(strict_types=1);

namespace App\Services\Images;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RemoteImageImporter
{
    public function import(string $url, string $directory, string $disk = 'public'): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid image URL [{$url}]");
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! str_ends_with($host, 'eldan.pl')) {
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

        $fileSize = strlen($contents);

        if ($fileSize > 10 * 1024 * 1024) {
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

    private function extensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException("Unsupported image MIME type [{$mimeType}]"),
        };
    }
}
