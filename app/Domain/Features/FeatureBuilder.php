<?php

namespace App\Domain\Features;

use App\Domain\Archive\FeatureCheckpointStore;
use App\Domain\Operations\ActionLog;
use App\Models\CoinGeckoMarketMapping;
use App\Repositories\TickerRepository;
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
            $tickers = app(TickerRepository::class);
            $checkpoints = app(FeatureCheckpointStore::class);
            $checkpoint = $fromMs === null ? null : $checkpoints->before($exchange, $symbol, $period, $fromMs);
            $sourceFrom = 0;
            if ($checkpoint !== null) {
                $elapsed = [];
                $anchorFrom = max(0, (int) $checkpoint['through_ms'] - 2_592_000_000);
                foreach ($tickers->streamHistory($exchange, $symbol, $period, $anchorFrom, (int) $checkpoint['through_ms']) as $timestamp => $raw) {
                    $elapsed[$timestamp] = $raw['close'];
                }
                $checkpoint['elapsed_closes'] = $elapsed;
                $sourceFrom = (int) $checkpoint['through_ms'] + 1;
            }
            $candles = $tickers->streamHistory($exchange, $symbol, $period, $sourceFrom, $cutoffMs);

            $mapping = CoinGeckoMarketMapping::query()
                ->where('status', 'resolved')
                ->whereHas('market', fn ($market) => $market->where('symbol', $symbol)
                    ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)))
                ->first();
            if ($mapping !== null && strtolower(explode('/', $symbol, 2)[1] ?? '') !== strtolower((string) $mapping->vs_currency)) {
                throw new RuntimeException('Context mapping must use the market exact quote currency.');
            }

            $context = new ContextFeatures;
            $keys = array_merge(FeatureEngine::KEYS, ContextFeatures::KEYS);
            $pending = [];
            $count = 0;
            $started = microtime(true);
            $latestCheckpoint = null;
            $snapshot = null;
            $snapshots = $mapping === null ? null : DB::table('market_context_snapshots')
                ->where('coin_id', $mapping->coin_id)
                ->where('vs_currency', strtolower((string) $mapping->vs_currency))
                ->where('observed_at_ms', '<=', $cutoffMs)
                ->orderBy('observed_at_ms')
                ->orderBy('snapshot_id')
                ->lazy(500)
                ->getIterator();
            $snapshots?->rewind();
            foreach ((new FeatureEngine)->rows($candles, $period, $cutoffMs, checkpoint: $checkpoint,
                checkpointCallback: function (array $state) use (&$latestCheckpoint): void {
                    $latestCheckpoint = $state;
                }) as $row) {
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
                $extra = $context->calculate(
                    $snapshot,
                    $row['close'],
                    $row['available_at_ms'],
                    config('features.coingecko.max_age_seconds') * 1000,
                    $mapping?->category,
                );
                $row['features'] = array_merge($row['features'], $extra['features']);
                $row['context_snapshot_id'] = $extra['snapshot_id'];
                $row['context_ready'] = $extra['context_ready'];
                $row['keys'] = $keys;
                $row['vector'] = array_values($row['features']);
                $row['missing'] = array_keys(array_filter($row['features'], fn ($value) => $value === null));
                $row['ready'] = $row['missing'] === [];
                $row['version'] = FeatureEngine::VERSION;
                $pending[] = [
                    'feature_id' => (string) Str::uuid7(),
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'period' => $period,
                    'microtimestamp' => $row['microtimestamp'],
                    'available_at_ms' => $row['available_at_ms'],
                    'version' => FeatureEngine::VERSION,
                    'payload' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $count++;
                if (count($pending) === 100) {
                    $this->save($pending);
                    $pending = [];
                }
            }
            if ($pending) {
                $this->save($pending);
            }
            if ($latestCheckpoint !== null) {
                $latestCheckpoint['feature_version'] = FeatureEngine::VERSION;
                $checkpoints->save($exchange, $symbol, $period, (int) $latestCheckpoint['through_ms'], $latestCheckpoint);
            }

            app(ActionLog::class)->write('features.built', ['exchange' => $exchange,
                'symbol' => $symbol, 'period' => $period, 'rows' => $count, 'outcome' => 'completed']);

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
