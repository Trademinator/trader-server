<?php

namespace App\Jobs;

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class TrainMarketIntelligence implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CRON = '0 4 * * 1';

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 86400;

    public array $backoff = [300, 900];

    public function __construct(public string $exchange, public string $symbol, public string $period, public string $week) {}

    public function uniqueId(): string
    {
        return ModelStore::marketKey($this->exchange, $this->symbol, $this->period).':'.$this->week.':'.IntelligenceTrainer::VERSION;
    }

    public function handle(MarketIntelligence $intelligence): void
    {
        $key = 'trademinator:intelligence-week:'.$this->uniqueId();
        $lock = Cache::lock($key.':lock', 720);
        if (! $lock->get()) {
            throw new RuntimeException('Weekly intelligence work is already running.');
        }
        try {
            if (Cache::has($key)) {
                return;
            }
            $intelligence->build($this->exchange, $this->symbol, $this->period, schema: config('intelligence.schema'),
                generation: hash('sha256', $this->uniqueId()));
            Cache::put($key, true, now()->addDays(14));
        } finally {
            $lock->release();
        }
    }
}
