<?php

namespace App\Providers;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\CategoryStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ShopConfigurationValue;
use App\Services\Delivery\CreateShipmentService;
use App\Services\Delivery\DeliveryGatewayRegistry;
use App\Services\Delivery\Polkurier\PolkurierDeliveryGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\Paynow\PaynowGateway;
use App\Services\Payments\Przelewy24\Przelewy24Gateway;
use App\Services\Shop\ShopConfiguration;
use App\Services\Storefront\ShopConfigurationCacheObserver;
use App\Services\Storefront\StorefrontCache;
use App\Services\Storefront\StorefrontCacheInvalidationObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Przelewy24Gateway::class);
        $this->app->singleton(PaynowGateway::class);

        $this->app->singleton(PaymentGatewayRegistry::class, function ($app): PaymentGatewayRegistry {
            return new PaymentGatewayRegistry([
                $app->make(Przelewy24Gateway::class),
                $app->make(PaynowGateway::class),
            ]);
        });

        $this->app->singleton(PolkurierDeliveryGateway::class);

        $this->app->singleton(DeliveryGatewayRegistry::class, function ($app): DeliveryGatewayRegistry {
            return new DeliveryGatewayRegistry([
                $app->make(PolkurierDeliveryGateway::class),
            ]);
        });

        $this->app->bind(CreatesShipments::class, CreateShipmentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        app(ShopConfiguration::class)->applyConfigOverrides();
        $this->observeStorefrontCacheInvalidation();
        $this->observeProductStorefrontCacheInvalidation();
        ShopConfigurationValue::observe(ShopConfigurationCacheObserver::class);
        $this->composeStorefrontNavigation();

        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }
    }

    /**
     * Share the active category tree with storefront navigation components.
     */
    protected function composeStorefrontNavigation(): void
    {
        View::composer([
            'partials.storefront.header',
            'partials.storefront.category-sidebar',
            'partials.storefront.footer',
        ], function ($view): void {
            $cache = app(StorefrontCache::class);

            $categories = $cache->rememberVersioned(
                StorefrontCache::NAMESPACE_NAVIGATION,
                'category-tree.v5',
                fn (): Collection => $this->buildStorefrontCategoryTree(
                    $this->storefrontCategorySnapshot(),
                ),
                $cache->categorySidebarTtlSeconds(),
            );

            $view->with([
                'storefrontNavigationCategories' => $categories,
                'storefrontSidebarCategories' => $categories,
            ]);
        });
    }

    /**
     * Register model observers that invalidate anonymous storefront caches.
     *
     * The cache itself may live in the existing local Redis container; this
     * keeps catalogue reads off RDS without requiring managed ElastiCache.
     */
    /**
     * Product invalidation is registered explicitly rather than through the
     * generic observer so catalogue cache generation changes deterministically
     * for every Product lifecycle mutation.
     */
    private function observeProductStorefrontCacheInvalidation(): void
    {
        $invalidate = static function (Product $product): void {
            app(StorefrontCache::class)->bump(
                StorefrontCache::NAMESPACE_CATALOGUE,
            );
        };

        Event::listen('eloquent.saved: '.Product::class, $invalidate);
        Event::listen('eloquent.deleted: '.Product::class, $invalidate);
        Event::listen('eloquent.restored: '.Product::class, $invalidate);
        Event::listen('eloquent.forceDeleted: '.Product::class, $invalidate);
    }

    private function observeStorefrontCacheInvalidation(): void
    {
        foreach ([
            ProductVariant::class,
            ProductImage::class,
            ProductAttributeValueImage::class,
            Attribute::class,
            AttributeValue::class,
            Category::class,
        ] as $modelClass) {
            $modelClass::observe(StorefrontCacheInvalidationObserver::class);
        }
    }

    /**
     * Return the exact category data used by storefront navigation.
     *
     * This query runs only when the versioned navigation cache is cold.
     * Category model mutations bump the navigation namespace immediately.
     *
     * @return Collection<int, Category>
     */
    private function storefrontCategorySnapshot(): Collection
    {
        return Category::query()
            ->where('status', CategoryStatus::ACTIVE->value)
            ->whereNotNull('slug')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug']);
    }

    /**
     * Build a recursively nested tree from the supplied public categories.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function buildStorefrontCategoryTree(Collection $categories): Collection
    {
        $categoriesByParent = $categories->groupBy(
            fn (Category $category): int => (int) ($category->parent_id ?? 0),
        );

        $buildTree = function (?int $parentId = null) use (&$buildTree, $categoriesByParent): Collection {
            return $categoriesByParent
                ->get($parentId ?? 0, collect())
                ->map(function (Category $category) use (&$buildTree): Category {
                    $category->setRelation('children', $buildTree((int) $category->id));

                    return $category;
                })
                ->values();
        };

        return $buildTree();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
