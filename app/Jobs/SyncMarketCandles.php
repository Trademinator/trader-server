<?php

namespace App\Jobs;

use App\Domain\MarketData\MarketDataSynchronizer;
use App\Domain\MarketData\CandleSyncPages;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncMarketCandles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 240];

    public function __construct(
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $period,
        public readonly int $from,
        public readonly int $to,
        public readonly bool $incremental = false,
        public readonly bool $repairGaps = false,
        public readonly int $pageSize = CandleSyncPages::DEFAULT_SIZE,
    ) {}

    public function handle(MarketDataSynchronizer $synchronizer, TickerRepository $tickers, CandleSyncPages $pages): void
    {
        $from = $this->from;
        if ($this->incremental) {
            $latest = $tickers->latestTimestamp($this->exchange, $this->symbol, $this->period);
            if ($latest !== null) {
                $from = max($from, intdiv($latest, 1000));
            }
        }
        if ($from > $this->to) {
            return;
        }

        $page = $pages->window($from, $this->to, $this->period, $this->pageSize);
        $synchronizer->sync($this->exchange, $this->symbol, $this->period, $page['from'], $page['to'], false, $this->repairGaps, $this->pageSize);

        if ($page['next'] !== null) {
            self::dispatch($this->exchange, $this->symbol, $this->period, $page['next'], $this->to, false, $this->repairGaps, $this->pageSize)
                ->delay(now()->addSecond());
        }
    }
}
