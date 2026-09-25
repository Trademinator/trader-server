<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\MarketData\CandleSyncPages;
use App\Jobs\SyncMarketCandles;
use App\Repositories\TickerRepository;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Trademinator\Time\to_unixtime;

final class SyncOHLCV extends Command
{
    protected $signature = 'trademinator:sync-ohlcv {exchange} {symbol} {period} {--from=7 days ago} {--to=now} {--incremental} {--repair-gaps} {--queue} {--page-size=90}';

    protected $description = 'Fetch and upsert exchange candles, optionally inspect and repair missing ranges';

    public function handle(MarketDataSynchronizer $synchronizer, TickerRepository $tickers, CandleSyncPages $pages): int
    {
        $from = to_unixtime((string) $this->option('from'));
        $to = to_unixtime((string) $this->option('to'));
        if ($from === false || $to === false || $from >= $to) {
            $this->error('Provide a valid --from and --to interval.');

            return self::FAILURE;
        }

        $exchange = (string) $this->argument('exchange');
        $symbol = (string) $this->argument('symbol');
        $period = (string) $this->argument('period');
        $size = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);
        if ($size === false) {
            $this->error('Page size must be between 10 and 100 candles.');

            return self::FAILURE;
        }

        if ($this->option('incremental')) {
            $latest = $tickers->latestTimestamp($exchange, $symbol, $period);
            if ($latest !== null) {
                $from = max($from, intdiv($latest, 1000));
            }
        }

        if ($from > $to) {
            $this->info('The stored candles are already beyond the requested end time.');

            return self::SUCCESS;
        }

        try {
            $pages->window($from, $to, $period, $size);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            if (in_array(config('queue.default'), ['sync', 'null'], true)) {
                $this->error('Use a persistent queue connection (database or redis) with --queue.');

                return self::FAILURE;
            }
            SyncMarketCandles::dispatch($exchange, $symbol, $period, $from, $to, false, (bool) $this->option('repair-gaps'), $size);
            $this->info('Market-data pages queued. Each successful job queues the next page.');

            return self::SUCCESS;
        }

        try {
            $result = ['pages' => 0, 'fetched' => 0, 'repaired' => 0, 'missing_ranges' => 0];
            do {
                $page = $pages->window($from, $to, $period, $size);
                $part = $synchronizer->sync($exchange, $symbol, $period, $page['from'], $page['to'], false, (bool) $this->option('repair-gaps'), $size);
                $result['pages']++;
                foreach (['fetched', 'repaired', 'missing_ranges'] as $key) {
                    $result[$key] += $part[$key];
                }
                $from = $page['next'];
            } while ($from !== null);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
