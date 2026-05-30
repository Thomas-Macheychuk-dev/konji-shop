<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\MergeGuestCartAfterLogin;
use App\Listeners\SendOrderConfirmationEmail;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\ShipmentTrackingAvailable;
use App\Listeners\SendShipmentTrackingEmail;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Login::class => [
            MergeGuestCartAfterLogin::class,
        ],
        OrderPlaced::class => [
            SendOrderConfirmationEmail::class,
        ],
        ShipmentTrackingAvailable::class => [
            SendShipmentTrackingEmail::class,
        ],
    ];

    public function boot(): void {}
}
