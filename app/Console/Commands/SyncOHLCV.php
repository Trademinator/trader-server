<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketDataSynchronizer;
use App\Jobs\SyncMarketCandles;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Trademinator\Time\to_unixtime;

final class SyncOHLCV extends Command
{
    protected $signature = 'trademinator:sync-ohlcv {exchange} {symbol} {period} {--from=7 days ago} {--to=now} {--incremental} {--repair-gaps} {--queue}';

    protected $description = 'Fetch and upsert exchange candles, optionally inspect and repair missing ranges';

    public function handle(MarketDataSynchronizer $synchronizer): int
    {
        $from = to_unixtime((string) $this->option('from'));
        $to = to_unixtime((string) $this->option('to'));
        if ($from === false || $to === false || $from >= $to) {
            $this->error('Provide a valid --from and --to interval.');

            return self::FAILURE;
        }

        $args = [
            (string) $this->argument('exchange'), (string) $this->argument('symbol'),
            (string) $this->argument('period'), $from, $to,
            (bool) $this->option('incremental'), (bool) $this->option('repair-gaps'),
        ];

        if ($this->option('queue')) {
            SyncMarketCandles::dispatch(...$args);
            $this->info('Market-data sync queued.');

            return self::SUCCESS;
        }

        try {
            $result = $synchronizer->sync(...$args);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
