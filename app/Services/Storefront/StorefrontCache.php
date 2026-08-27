<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class StorefrontCache
{
    public const NAMESPACE_CATALOGUE = 'catalogue';

    public const NAMESPACE_NAVIGATION = 'navigation';

    public const NAMESPACE_SHOP_CONFIGURATION = 'shop-configuration';

    public function remember(string $key, Closure $callback, ?int $ttlSeconds = null): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $ttlSeconds ??= $this->productPageTtlSeconds();

        if ($ttlSeconds <= 0) {
            return $callback();
        }

        return $this->repository()->remember($key, $ttlSeconds, $callback);
    }

    public function rememberVersioned(
        string $namespace,
        string $key,
        Closure $callback,
        ?int $ttlSeconds = null,
    ): mixed {
        return $this->remember(
            $this->versionedKey($namespace, $key),
            $callback,
            $ttlSeconds,
        );
    }

    public function versionedKey(string $namespace, string $key): string
    {
        return sprintf(
            'storefront.%s.%s.%s',
            $namespace,
            $this->version($namespace),
            ltrim($key, '.'),
        );
    }

    public function bump(string $namespace): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->repository()->forever(
            $this->versionKey($namespace),
            bin2hex(random_bytes(8)),
        );
    }

    public function enabled(): bool
    {
        return (bool) config('storefront.cache.enabled', true);
    }

    public function productPageTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.product_pages_ttl', 3600));
    }

    public function categorySidebarTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.category_sidebar_ttl', 3600));
    }

    public function homePageTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.home_page_ttl', 300));
    }

    public function categoryPageTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.category_pages_ttl', 600));
    }

    public function shopConfigurationTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.shop_configuration_ttl', 300));
    }

    private function version(string $namespace): string
    {
        if (! $this->enabled()) {
            return 'disabled';
        }

        return (string) $this->repository()->get(
            $this->versionKey($namespace),
            '1',
        );
    }

    private function versionKey(string $namespace): string
    {
        return 'storefront.version.'.trim($namespace);
    }

    private function repository(): Repository
    {
        $store = config('storefront.cache.store');

        if (filled($store)) {
            return Cache::store((string) $store);
        }

        return Cache::store();
    }
}
