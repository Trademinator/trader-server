<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketHistoryBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillOHLCV extends Command
{
    protected $signature = 'trademinator:backfill-ohlcv {--exchange=} {--symbol=} {--period=} {--status} {--resume}';

    protected $description = 'Queue resumable older OHLCV history for subscribed markets, or inspect and resume paused backfills';

    public function handle(MarketHistoryBackfill $history, BackfillIntelligence $intelligence): int
    {
        $exchange = $this->option('exchange');
        $symbol = $this->option('symbol');
        $period = $this->option('period');
        if (($period !== null && ! in_array($period, CandleTimeframe::SUPPORTED, true))
            || ($this->option('resume') && ($this->option('status') || ! $exchange || ! $symbol || ! $period))) {
            $this->error('Use a supported period. --resume requires --exchange, --symbol and --period, without --status.');

            return self::FAILURE;
        }
        if ($this->option('status')) {
            $rows = DB::table('market_history_backfills as history')
                ->join('markets', 'markets.market_id', '=', 'history.market_id')
                ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
                ->when($exchange !== null, fn ($query) => $query->where('exchanges.class', $exchange))
                ->when($symbol !== null, fn ($query) => $query->where('markets.symbol', $symbol))
                ->when($period !== null, fn ($query) => $query->where('history.period', $period))
                ->orderBy('exchanges.class')->orderBy('markets.symbol')->orderBy('history.period')
                ->select('exchanges.class as exchange', 'markets.symbol', 'history.*')->get();
            $this->line($rows->toJson(JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if (! config('history_backfill.enabled')) {
            $this->info('Historical backfill is disabled (HISTORY_BACKFILL_ENABLED=false).');

            return self::SUCCESS;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Historical backfill requires a persistent queue connection (database or redis).');

            return self::FAILURE;
        }
        $count = $history->dispatchDue($exchange, $symbol, $period, (bool) $this->option('resume'));
        $this->info("Queued {$count} shared-market history backfills on the ".config('history_backfill.queue').' queue.');
        $builds = $intelligence->dispatchDue($exchange, $symbol, $period);
        $this->info("Queued {$builds} backfill intelligence rebuild steps on the ".config('intelligence.queue').' queue.');

        return self::SUCCESS;
    }
}
