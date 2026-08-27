<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;

final class StorefrontCacheInvalidationObserver
{
    public function __construct(
        private readonly StorefrontCache $cache,
    ) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $this->cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

        if ($model instanceof Category) {
            $this->cache->bump(StorefrontCache::NAMESPACE_NAVIGATION);
        }
    }
}
