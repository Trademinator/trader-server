<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every application node may run schedule:run. The shared cache elects one;
// the feed table's atomic claim also protects against duplicate dispatches.
Illuminate\Support\Facades\Schedule::command('trademinator:dispatch-market-feeds')
    ->everyMinute()->onOneServer()->withoutOverlapping(1);
