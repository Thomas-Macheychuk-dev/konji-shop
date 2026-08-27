<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\ShopConfigurationValue;

final class ShopConfigurationCacheObserver
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    public function saved(ShopConfigurationValue $value): void
    {
        $this->cache->bump(StorefrontCache::NAMESPACE_SHOP_CONFIGURATION);
    }

    public function deleted(ShopConfigurationValue $value): void
    {
        $this->cache->bump(StorefrontCache::NAMESPACE_SHOP_CONFIGURATION);
    }
}
