<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class StorefrontCache
{
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

    public function enabled(): bool
    {
        return (bool) config('storefront.cache.enabled', true);
    }

    public function productPageTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.product_pages_ttl', 86400));
    }

    public function categorySidebarTtlSeconds(): int
    {
        return max(0, (int) config('storefront.cache.category_sidebar_ttl', 86400));
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
