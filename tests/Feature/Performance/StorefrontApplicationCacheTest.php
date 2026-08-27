<?php

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\ShopConfigurationValue;
use App\Services\Shop\ShopConfiguration;
use App\Services\Storefront\ActiveCategorySubtree;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('storefront.cache.enabled', true);
    Config::set('storefront.cache.store', 'array');
    Config::set('storefront.cache.product_pages_ttl', 3600);
    Config::set('storefront.cache.category_sidebar_ttl', 3600);
    Config::set('storefront.cache.home_page_ttl', 300);
    Config::set('storefront.cache.category_pages_ttl', 600);
    Config::set('storefront.cache.shop_configuration_ttl', 300);

    Cache::store('array')->flush();
});

it('reuses versioned cache values until their namespace is invalidated', function (): void {
    $cache = app(StorefrontCache::class);
    $calls = 0;

    $resolve = function () use (&$calls): int {
        return ++$calls;
    };

    expect($cache->rememberVersioned(
        StorefrontCache::NAMESPACE_CATALOGUE,
        'test-value',
        $resolve,
        300,
    ))->toBe(1);

    expect($cache->rememberVersioned(
        StorefrontCache::NAMESPACE_CATALOGUE,
        'test-value',
        $resolve,
        300,
    ))->toBe(1)
        ->and($calls)->toBe(1);

    $cache->bump(StorefrontCache::NAMESPACE_CATALOGUE);

    expect($cache->rememberVersioned(
        StorefrontCache::NAMESPACE_CATALOGUE,
        'test-value',
        $resolve,
        300,
    ))->toBe(2)
        ->and($calls)->toBe(2);
});

it('caches the active category subtree and invalidates it after a category mutation', function (): void {
    $root = Category::query()->create([
        'name' => 'Root cache category',
        'slug' => 'root-cache-category',
        'status' => CategoryStatus::ACTIVE,
    ]);

    Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Child cache category',
        'slug' => 'child-cache-category',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $service = app(ActiveCategorySubtree::class);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'active_category_tree')) {
            $queries++;
        }
    });

    expect($service->ids($root))->toHaveCount(2)
        ->and($service->ids($root))->toHaveCount(2)
        ->and($queries)->toBe(1);

    Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Second child cache category',
        'slug' => 'second-child-cache-category',
        'status' => CategoryStatus::ACTIVE,
    ]);

    expect($service->ids($root))->toHaveCount(3)
        ->and($queries)->toBe(2);
});

it('loads editable shop configuration once and invalidates it after direct model writes', function (): void {
    ShopConfigurationValue::query()->create([
        'key' => 'legal.seller.email',
        'value' => 'first@example.com',
    ]);

    $configuration = app(ShopConfiguration::class);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'shop_configuration_values')) {
            $queries++;
        }
    });

    expect($configuration->get('legal.seller.email'))->toBe('first@example.com')
        ->and($configuration->get('legal.seller.email'))->toBe('first@example.com')
        ->and($queries)->toBe(1);

    ShopConfigurationValue::query()
        ->where('key', 'legal.seller.email')
        ->firstOrFail()
        ->update(['value' => 'second@example.com']);

    expect($configuration->get('legal.seller.email'))->toBe('second@example.com');
});
