<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('traffic:refresh-google-crawler-ranges')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('polkurier:sync-shipments --limit=50')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('paynow:reconcile-refunds --limit=50')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
