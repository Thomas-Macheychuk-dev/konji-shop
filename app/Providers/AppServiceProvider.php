<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
