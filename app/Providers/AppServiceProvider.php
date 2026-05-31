<?php

namespace App\Providers;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Services\Delivery\DeliveryGatewayRegistry;
use App\Services\Delivery\Polkurier\PolkurierDeliveryGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\Paynow\PaynowGateway;
use App\Services\Payments\Przelewy24\Przelewy24Gateway;
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
            $view->with('storefrontSidebarCategories', Category::query()
                ->whereNull('parent_id')
                ->where('status', CategoryStatus::ACTIVE->value)
                ->orderBy('name')
                ->get(['id', 'name', 'slug']));
        });
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
