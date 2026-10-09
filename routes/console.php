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
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::command('trademinator:backfill-ohlcv')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

// Algorithm versions are reevaluated in small shared-market batches. The
// current selected period remains live until a replacement fully qualifies.
Schedule::command('trademinator:evaluate-candle-period --outdated-only')
    ->everyFifteenMinutes()->onOneServer()->withoutOverlapping(15);

Schedule::command('trademinator:collect-market-context')
    ->hourly()->onOneServer()->withoutOverlapping(60);

// GDELT publishes a new GKG batch roughly every 15 minutes. Poll cheaply every
// five minutes; the collector downloads a ZIP only when lastupdate.txt changes.
Schedule::command('trademinator:collect-market-events')
    ->everyFiveMinutes()->onOneServer()->withoutOverlapping(15)->runInBackground();

Schedule::command('trademinator:dispatch-market-features')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::command('trademinator:dispatch-market-signals')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::command('trademinator:refresh-market-discovery')
    ->hourlyAt(10)->onOneServer()->withoutOverlapping(15)->runInBackground();

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

// M4.3 exports and independently verifies complete old months. It never
// deletes hot rows; archive pruning remains deliberately disabled.
Schedule::command('trademinator:archive-eligible-tickers')
    ->dailyAt('04:10')->onOneServer()->withoutOverlapping(120)->runInBackground();

// Prune expired multipart export/import staging files and metadata, never permanent archives.
Schedule::command('trademinator:prune-portable-archives')
    ->dailyAt('04:40')->onOneServer()->withoutOverlapping(60)->runInBackground();
