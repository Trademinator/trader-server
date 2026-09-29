<?php

use App\Jobs\TrainMarketIntelligence;
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

Schedule::command('trademinator:backfill-ohlcv')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::command('trademinator:collect-market-context')
    ->hourly()->onOneServer()->withoutOverlapping(60);

Schedule::command('trademinator:dispatch-market-features')
    ->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

// Every node has its own installed source and runtime metadata file. The command
// uses a local file lock and a shared cache lock around database synchronization.
Schedule::command('trademinator:refresh-exchanges')->dailyAt('03:20');

// CPU-heavy training is drained on the intelligence queue by cron workers.
Schedule::command('trademinator:dispatch-market-intelligence')
    ->cron(TrainMarketIntelligence::CRON)->onOneServer()->withoutOverlapping(10);

// Refit empirical cross-exchange evidence and its downstream model together.
Schedule::command('trademinator:dispatch-lead-lag')
    ->dailyAt('03:45')->onOneServer()->withoutOverlapping(10);

Schedule::command('trademinator:prune-access-statistics')
    ->dailyAt('02:40')->onOneServer()->withoutOverlapping(60);
