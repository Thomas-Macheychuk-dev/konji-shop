<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\ShipmentTrackingAvailable;
use App\Events\WithdrawalRequestRefunded;
use App\Events\WithdrawalRequestSubmitted;
use App\Listeners\MergeGuestCartAfterLogin;
use App\Listeners\SendOrderConfirmationEmail;
use App\Listeners\SendShipmentTrackingEmail;
use App\Listeners\SendWithdrawalAcknowledgementEmail;
use App\Listeners\SendWithdrawalRefundedEmail;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
        WithdrawalRequestSubmitted::class => [
            SendWithdrawalAcknowledgementEmail::class,
        ],
        WithdrawalRequestRefunded::class => [
            SendWithdrawalRefundedEmail::class,
        ],
    ];

    public function boot(): void {}
}
