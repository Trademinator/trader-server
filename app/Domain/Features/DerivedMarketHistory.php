<?php

namespace App\Domain\Features;

use App\Domain\MarketData\ClosedCandleAggregator;
use App\Models\MarketFeed;
use App\Models\Ticker;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class DerivedMarketHistory
{
    public function __construct(private ClosedCandleAggregator $aggregator, private TickerRepository $tickers, private FeatureBuilder $features) {}

    public function build(string $exchange, string $symbol, string $base, string $period, ?int $fromMs = null, ?int $asOfMs = null): array
    {
        $selected = MarketFeed::query()->whereHas('market', fn ($query) => $query->where('symbol', $symbol)
            ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)))->value('selected_period');
        if ($selected !== $base) {
            throw new InvalidArgumentException('Base period must match the market feed selected reliable period.');
        }
        $baseMs = $this->aggregator->duration($base);
        $targetMs = $this->aggregator->duration($period);
        if ($targetMs <= $baseMs || $targetMs % $baseMs !== 0) {
            throw new InvalidArgumentException('Derived period must be a larger exact multiple of its base period.');
        }
        $asOfMs = min($asOfMs ?? now()->getTimestampMs(), now()->getTimestampMs());
        $fromMs ??= max(0, $asOfMs - (config('intelligence.max_rows') + 60) * $targetMs);
        if ($fromMs < 0 || $fromMs > $asOfMs) {
            throw new InvalidArgumentException('Invalid derivation range.');
        }
        $fromMs = (int) (ceil($fromMs / $targetMs) * $targetMs);
        $toMs = intdiv($asOfMs, $targetMs) * $targetMs;
        $lock = Cache::lock('trademinator:features:'.hash('sha256', "$exchange|$symbol|$period"), 720);
        if (! $lock->get()) {
            throw new RuntimeException('Target features or a dataset are already being built.');
        }
        $count = 0;
        try {
            $target = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period);
            if ((clone $target)->where(fn ($query) => $query->whereNull('payload->derived_from')->orWhere('payload->derived_from', '!=', $base))->exists()) {
                throw new InvalidArgumentException('Target contains exchange candles or another derivation; existing source history will not be overwritten.');
            }
            $started = microtime(true);
            DB::transaction(function () use ($exchange, $symbol, $base, $period, $fromMs, $toMs, $asOfMs, $target, $started, &$count): void {
                $source = (function () use ($exchange, $symbol, $base, $fromMs, $asOfMs, $started) {
                    foreach (Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $base)
                        ->whereBetween('microtimestamp', [$fromMs, $asOfMs])->orderBy('microtimestamp')->lazy(500) as $ticker) {
                        if (microtime(true) - $started > 240) {
                            throw new RuntimeException('Derived history time budget exceeded while reading base candles.');
                        }
                        $raw = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                        $raw['microtimestamp'] = (int) $ticker->microtimestamp;
                        yield $raw;
                    }
                })();
                // Regenerate only derived buckets in the requested interval, including removal of newly detected gaps.
                (clone $target)->where('microtimestamp', '>=', $fromMs)->where('microtimestamp', '<', $toMs)->delete();
                $pending = [];
                foreach ($this->aggregator->rows($source, $base, $period, $asOfMs) as $bar) {
                    if (++$count > config('intelligence.max_rows') + 60 || microtime(true) - $started > 240) {
                        throw new RuntimeException('Derived history exceeds its bounded range or time budget.');
                    }
                    $pending[] = $bar;
                    if (count($pending) === 100) {
                        $this->tickers->saveTickers($exchange, $symbol, $period, $pending);
                        $pending = [];
                    }
                }
                if ($pending !== []) {
                    $this->tickers->saveTickers($exchange, $symbol, $period, $pending);
                }
                DB::table('market_features')->where('exchange', $exchange)->where('symbol', $symbol)
                    ->where('period', $period)->where('microtimestamp', '>=', $fromMs)->delete();
            });
        } finally {
            $lock->release();
        }
        $features = $this->features->build($exchange, $symbol, $period, fromMs: $fromMs);

        return ['base_period' => $base, 'period' => $period, 'candles' => $count, 'features' => $features,
            'from_ms' => $fromMs, 'as_of_ms' => $asOfMs];
    }
}
