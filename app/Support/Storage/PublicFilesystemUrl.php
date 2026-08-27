<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;

final class PublicFilesystemUrl
{
    private const PROBE_PATH = '__konji_public_filesystem_probe__';

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        if ((string) config('filesystems.disks.public.driver') === 'local') {
            return '/storage/'.$path;
        }

        return Storage::disk('public')->url($path);
    }

    public static function path(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '') {
            return null;
        }

        $urlPath = parse_url($url, PHP_URL_PATH);

        if (is_string($urlPath) && str_starts_with($urlPath, '/storage/')) {
            return ltrim(rawurldecode(substr($urlPath, strlen('/storage/'))), '/');
        }

        $probeUrl = Storage::disk('public')->url(self::PROBE_PATH);
        $probePosition = strrpos($probeUrl, self::PROBE_PATH);

        if ($probePosition === false) {
            return null;
        }

        $baseUrl = substr($probeUrl, 0, $probePosition);

        if ($baseUrl === '' || ! str_starts_with($url, $baseUrl)) {
            return null;
        }

        $path = ltrim(rawurldecode(substr($url, strlen($baseUrl))), '/');

        return $path !== '' ? $path : null;
    }
}
