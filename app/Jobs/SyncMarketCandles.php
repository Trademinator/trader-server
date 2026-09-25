<?php

namespace App\Jobs;

use App\Domain\MarketData\MarketDataSynchronizer;
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
    ) {}

    public function handle(MarketDataSynchronizer $synchronizer): void
    {
        $synchronizer->sync($this->exchange, $this->symbol, $this->period, $this->from, $this->to, $this->incremental, $this->repairGaps);
    }
}
