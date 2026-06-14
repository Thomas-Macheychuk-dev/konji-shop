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
        $this->composeStorefrontCategorySidebar();

        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }
    }


    /**
     * Share active top-level categories with the storefront sidebar.
     */
    protected function composeStorefrontCategorySidebar(): void
    {
        View::composer('partials.storefront.category-sidebar', function ($view): void {
            $cache = app(StorefrontCache::class);
            $cacheKey = 'storefront.category-sidebar.v1.'.sha1(json_encode([
                'max_updated_at' => $this->topLevelCategoryCacheTimestamp(),
                'count' => Category::query()
                    ->whereNull('parent_id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->count(),
            ], JSON_THROW_ON_ERROR));

            $view->with('storefrontSidebarCategories', $cache->remember(
                $cacheKey,
                fn () => Category::query()
                    ->whereNull('parent_id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug']),
                $cache->categorySidebarTtlSeconds(),
            ));
        });
    }

    private function topLevelCategoryCacheTimestamp(): ?string
    {
        $timestamp = Category::query()
            ->whereNull('parent_id')
            ->max('updated_at');

        return $timestamp === null ? null : (string) $timestamp;
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
