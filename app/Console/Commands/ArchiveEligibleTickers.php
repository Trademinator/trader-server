<?php

namespace App\Console\Commands;

use App\Domain\Archive\TickerArchive;
use App\Models\Ticker;
use Illuminate\Console\Command;

class ArchiveEligibleTickers extends Command
{
    protected $signature = 'trademinator:archive-eligible-tickers {--dry-run}';

    protected $description = 'Export and verify complete ticker months older than the configured hot-retention boundary; never prunes rows.';

    public function handle(TickerArchive $archive): int
    {
        $cutoff = now('UTC')->subDays((int) config('archive.archive_after_days'))->startOfMonth()->getTimestampMs();
        $markets = Ticker::query()->select(['exchange', 'symbol', 'period'])->distinct()->orderBy('exchange')->orderBy('symbol')->orderBy('period')->cursor();
        $created = $failed = 0;
        foreach ($markets as $market) {
            $oldest = Ticker::query()->where('exchange', $market->exchange)->where('symbol', $market->symbol)->where('period', $market->period)
                ->where('microtimestamp', '<', $cutoff)->min('microtimestamp');
            if ($oldest === null) {
                continue;
            }
            $cursor = now('UTC')->setTimestamp(intdiv((int) $oldest, 1000))->startOfMonth();
            $until = now('UTC')->setTimestamp(intdiv($cutoff, 1000))->startOfMonth();
            while ($cursor->lt($until)) {
                try {
                    $result = $archive->archiveMonth($market->exchange, $market->symbol, $market->period,
                        $cursor->year, $cursor->month, (bool) $this->option('dry-run'));
                    if (($result['status'] ?? null) !== 'empty') {
                        $created++;
                    }
                } catch (\Throwable $error) {
                    $failed++;
                    $this->error($market->exchange.' '.$market->symbol.' '.$market->period.' '.$cursor->format('Y-m').': '.$error->getMessage());
                }
                $cursor->addMonth();
            }
        }
        $this->info("Archive pass complete: {$created} shard(s), {$failed} failure(s), pruning disabled.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
