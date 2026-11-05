<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('traffic:refresh-crawler-ranges')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('polkurier:sync-shipments --limit=50')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('paynow:reconcile-refunds --limit=50')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Newly imported images get their small listing copy within the hour.
Schedule::command('catalogue:generate-thumbnails --write --limit=300')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('complaints:send-deadline-alerts')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
