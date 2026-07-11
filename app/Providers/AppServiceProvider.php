<?php

namespace App\Providers;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Services\Delivery\DeliveryGatewayRegistry;
use App\Services\Delivery\Polkurier\PolkurierDeliveryGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\Paynow\PaynowGateway;
use App\Services\Payments\Przelewy24\Przelewy24Gateway;
use App\Services\Shop\ShopConfiguration;
use App\Services\Storefront\StorefrontCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use App\Contracts\Delivery\CreatesShipments;
use App\Services\Delivery\CreateShipmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

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
            $categorySnapshot = $this->storefrontCategorySnapshot();
            $cacheKey = 'storefront.navigation.v4.'.hash('sha256', json_encode(
                $categorySnapshot
                    ->map(fn (Category $category): array => [
                        'id' => (int) $category->id,
                        'parent_id' => $category->parent_id === null
                            ? null
                            : (int) $category->parent_id,
                        'name' => (string) $category->name,
                        'slug' => (string) $category->slug,
                    ])
                    ->values()
                    ->all(),
                JSON_THROW_ON_ERROR,
            ));

            $categories = $cache->remember(
                $cacheKey,
                fn (): Collection => $this->buildStorefrontCategoryTree($categorySnapshot),
                $cache->categorySidebarTtlSeconds(),
            );

            $view->with([
                'storefrontNavigationCategories' => $categories,
                'storefrontSidebarCategories' => $categories,
            ]);
        });
    }

    /**
     * Return the exact category data used by storefront navigation.
     *
     * The snapshot is also used to build the cache key. This prevents a
     * category tree from another database state being reused when the active
     * category count and second-level updated_at timestamp happen to match.
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
     * @param Collection<int, Category> $categories
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
