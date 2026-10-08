<?php

namespace App\Jobs;

use App\Domain\Intelligence\MarketIntelligence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class AnalyzeMarketAutoLabels implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 2200;

    public function __construct(
        public string $exchange,
        public string $symbol,
        public string $period,
        public string $cacheKey,
    ) {}

    public function handle(MarketIntelligence $intelligence): void
    {
        Cache::put($this->cacheKey.':status', 'running', now()->addDay());
        try {
            $result = $intelligence->build(
                $this->exchange, $this->symbol, $this->period,
                schema: (string) config('intelligence.schema'),
                contextFallback: (string) config('intelligence.context_fallback', 'none')
            );
            Cache::put($this->cacheKey.':status', 'completed', now()->addDay());
            Cache::put($this->cacheKey.':summary', [
                'model_id' => $result['model_id'] ?? null,
                'dataset_id' => $result['dataset_id'] ?? null,
                'analyzed_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            Cache::forget($this->cacheKey.':lock');
        }
    }

    public function failed(\Throwable $error): void
    {
        Cache::put($this->cacheKey.':status', 'failed', now()->addDay());
        Cache::forget($this->cacheKey.':lock');
    }
}
