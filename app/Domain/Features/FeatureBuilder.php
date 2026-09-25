<?php

namespace App\Domain\Features;

use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class FeatureBuilder
{
    public function build(string $exchange, string $symbol, string $period, ?int $cutoffMs = null, ?int $fromMs = null): int
    {
        $cutoffMs = min($cutoffMs ?? PHP_INT_MAX, (int) floor(microtime(true) * 1000));
        $lock = Cache::lock('trademinator:features:'.hash('sha256', "$exchange|$symbol|$period"), 720);
        if (! $lock->get()) {
            throw new RuntimeException('Features are already being built for this market and period.');
        }
        try {
            // Replay from the earliest stored candle for reproducible EMA/Wilder state.
            // lazy() bounds DB memory; features never rewrite the source ticker payload.
            $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                ->where('microtimestamp', '<=', $cutoffMs)->orderBy('microtimestamp');
            $candles = (function () use ($query) {
                foreach ($query->lazy(500) as $ticker) {
                    $raw = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                    $raw['microtimestamp'] = (int) $ticker->microtimestamp;
                    yield $raw;
                }
            })();
            $mapping = config('features.coingecko.markets', [])[$exchange.':'.$symbol] ?? null;
            if ($mapping !== null && strtolower(explode('/', $symbol)[1] ?? '') !== strtolower($mapping['vs_currency'] ?? '')) {
                throw new RuntimeException('Context mapping must use the market exact quote currency.');
            }
            $context = new ContextFeatures;
            $keys = array_merge(FeatureEngine::KEYS, ContextFeatures::KEYS);
            $pending = [];
            $count = 0;
            $started = microtime(true);
            $snapshot = null;
            $snapshots = $mapping === null ? null : DB::table('market_context_snapshots')
                ->where('coin_id', $mapping['id'])->where('vs_currency', strtolower($mapping['vs_currency']))
                ->where('observed_at_ms', '<=', $cutoffMs)->orderBy('observed_at_ms')->orderBy('snapshot_id')->lazy(500)->getIterator();
            $snapshots?->rewind();
            foreach ((new FeatureEngine)->rows($candles, $period, $cutoffMs) as $row) {
                if (microtime(true) - $started > 540) {
                    throw new RuntimeException('Feature replay exceeded 540 seconds; reduce the stored history or run a dedicated offline dataset build.');
                }
                while ($snapshots !== null && $snapshots->valid() && $snapshots->current()->observed_at_ms <= $row['available_at_ms']) {
                    $snapshot = (array) $snapshots->current();
                    $snapshot['payload'] = json_decode($snapshot['payload'], true, flags: JSON_THROW_ON_ERROR);
                    $snapshots->next();
                }
                if ($fromMs !== null && $row['microtimestamp'] < $fromMs) {
                    continue;
                }
                $extra = $context->calculate($snapshot, $row['close'], $row['available_at_ms'],
                    config('features.coingecko.max_age_seconds') * 1000, $mapping['category'] ?? null);
                $row['features'] = array_merge($row['features'], $extra['features']);
                $row['context_snapshot_id'] = $extra['snapshot_id'];
                $row['context_ready'] = $extra['context_ready'];
                $row['keys'] = $keys;
                $row['vector'] = array_values($row['features']);
                $row['missing'] = array_keys(array_filter($row['features'], fn ($value) => $value === null));
                $row['ready'] = $row['missing'] === [];
                $row['version'] = FeatureEngine::VERSION;
                $pending[] = ['feature_id' => (string) Str::uuid7(), 'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                    'microtimestamp' => $row['microtimestamp'], 'available_at_ms' => $row['available_at_ms'], 'version' => FeatureEngine::VERSION,
                    'payload' => json_encode($row, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
                $count++;
                if (count($pending) === 100) {
                    $this->save($pending);
                    $pending = [];
                }
            }
            if ($pending) {
                $this->save($pending);
            }

            return $count;
        } finally {
            $lock->release();
        }
    }

    private function save(array $rows): void
    {
        DB::table('market_features')->upsert($rows, ['exchange', 'symbol', 'period', 'microtimestamp', 'version'], ['payload', 'available_at_ms', 'updated_at']);
    }
}
