<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\MergeGuestCartAfterLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Login::class => [
            MergeGuestCartAfterLogin::class,
        ],
    ];
}
