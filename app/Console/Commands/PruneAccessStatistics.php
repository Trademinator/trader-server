<?php

namespace App\Console\Commands;

use App\Domain\Operations\ActionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAccessStatistics extends Command
{
    protected $signature = 'trademinator:prune-access-statistics';

    protected $description = 'Delete access statistics older than the configured retention period';

    /**
     * Execute the console command.
     */
    public function handle(ActionLog $log): int
    {
        $cutoff = now('UTC')->subDays(config('operations.retention_days') - 1)->toDateString();
        $count = 0;
        foreach (['access_daily_stats', 'access_daily_visitors'] as $table) {
            do {
                // Keep retention cleanup memory bounded on both SQLite and MariaDB.
                $rows = DB::table($table)->where('day', '<', $cutoff)->limit(1000)->get(
                    $table === 'access_daily_stats' ? ['bucket_id'] : ['day', 'visitor_hash']);
                foreach ($rows as $row) {
                    $count += DB::table($table)->where((array) $row)->delete();
                }
            } while ($rows->count() === 1000);
        }
        $log->write('access.pruned', ['rows' => $count, 'outcome' => 'completed']);
        $this->info('Deleted '.$count.' expired access-statistics records.');

        return self::SUCCESS;
    }
}
