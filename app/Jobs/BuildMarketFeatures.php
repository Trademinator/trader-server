<?php

namespace App\Jobs;

use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class BuildMarketFeatures implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    public function __construct(public string $exchange, public string $symbol, public string $period)
    {
        $this->onQueue((string) config('features.queue', 'features'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', "$this->exchange|$this->symbol|$this->period");
    }

    public function handle(FeatureBuilder $builder, TickerRepository $tickers): void
    {
        $latestCandle = $tickers->latestTimestamp($this->exchange, $this->symbol, $this->period);
        if ($latestCandle === null) {
            return;
        }

        $latestFeature = DB::table('market_features')
            ->where('exchange', $this->exchange)
            ->where('symbol', $this->symbol)
            ->where('period', $this->period)
            ->where('version', FeatureEngine::VERSION)
            ->max('microtimestamp');

        // A previous queued build may have caught up before this job started.
        if ($latestFeature !== null && (int) $latestFeature >= $latestCandle) {
            return;
        }

        $fromMs = null;
        if ($latestFeature !== null) {
            // Rebuild a small overlap so any recent exchange correction is folded
            // into the new feature rows while the checkpoint preserves older state.
            $timeframe = new CandleTimeframe;
            $fromMs = (int) $latestFeature;
            for ($i = 0; $i < 3; $i++) {
                $fromMs = max(0, $timeframe->previous($fromMs, $this->period));
            }
        }

        $builder->build($this->exchange, $this->symbol, $this->period, fromMs: $fromMs);
    }
}
