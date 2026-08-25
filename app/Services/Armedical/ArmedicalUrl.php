<?php

declare(strict_types=1);

namespace App\Services\Armedical;

use Illuminate\Support\Str;

final class ArmedicalUrl
{
    public const BASE_URL = 'https://armedical.pl/';

    public const CATALOGUE_URL = 'https://armedical.pl/katalog/';

    public const OFFER_ARCHIVE_URL = 'https://armedical.pl/oferta/';

    private const HOSTS = [
        'armedical.pl',
        'www.armedical.pl',
    ];

    public static function normalize(string $url, ?string $baseUrl = null): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || str_starts_with($url, '#') || preg_match('#^(?:mailto|tel|javascript):#i', $url) === 1) {
            return null;
        }

        $baseUrl ??= self::BASE_URL;

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $base = parse_url($baseUrl);

            if (! is_array($base) || ! is_string($base['host'] ?? null)) {
                return null;
            }

            $basePath = (string) ($base['path'] ?? '/');

            if (str_starts_with($url, '/')) {
                $path = $url;
            } else {
                $directory = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';
                $path = $directory.$url;
            }

            $url = 'https://'.$base['host'].$path;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/').'/';
        }

        return 'https://armedical.pl'.$path;
    }

    public static function category(string $url, ?string $baseUrl = null): ?string
    {
        $url = self::normalize($url, $baseUrl);

        if ($url === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/kategoria-produktow/[^/]+/(?:page/\d+/)?$#', $path) === 1
            ? $url
            : null;
    }

    public static function categoryCanonical(string $url, ?string $baseUrl = null): ?string
    {
        $url = self::category($url, $baseUrl);

        if ($url === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#/page/\d+/$#', '/', $path) ?: $path;

        return 'https://armedical.pl'.$path;
    }

    public static function product(string $url, ?string $baseUrl = null): ?string
    {
        $url = self::normalize($url, $baseUrl);

        if ($url === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/oferta/([^/]+)/$#', $path, $matches) !== 1) {
            return null;
        }

        if (in_array($matches[1], ['page'], true)) {
            return null;
        }

        return $url;
    }

    public static function listingPage(string $url, ?string $baseUrl = null): ?string
    {
        $url = self::normalize($url, $baseUrl);

        if ($url === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/oferta/(?:page/\d+/)?$#', $path) === 1) {
            return $url;
        }

        if (preg_match('#^/kategoria-produktow/[^/]+/(?:page/\d+/)?$#', $path) === 1) {
            return $url;
        }

        return null;
    }

    public static function productSlug(string $url): ?string
    {
        $url = self::product($url);

        if ($url === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        return $segments[1] ?? null;
    }

    public static function categorySlug(string $url): ?string
    {
        $url = self::categoryCanonical($url);

        if ($url === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));

        return $segments[1] ?? null;
    }

    public static function externalProductId(string $url): ?string
    {
        $slug = self::productSlug($url);

        return $slug === null ? null : 'armedical-'.$slug;
    }

    public static function slugify(string $value): string
    {
        return Str::slug(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
