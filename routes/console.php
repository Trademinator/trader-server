<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every application node may run schedule:run. The shared cache elects one;
// the feed table's atomic claim also protects against duplicate dispatches.
Schedule::command('trademinator:dispatch-market-feeds')
    ->everyMinute()->onOneServer()->withoutOverlapping(1);

Schedule::command('trademinator:collect-market-context')
    ->hourly()->onOneServer()->withoutOverlapping(60);

Schedule::command('trademinator:dispatch-market-features')
    ->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
