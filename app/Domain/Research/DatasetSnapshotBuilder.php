<?php

namespace App\Domain\Research;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Models\MarketFeature;
use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DatasetSnapshotBuilder
{
    public function __construct(private DatasetStore $store) {}

    public function build(string $exchange, string $symbol, string $period, LabelDefinition|SemanticLabels $definition,
        string $schema = 'core', array $custom = [], ?int $fromMs = null, ?int $toMs = null, ?int $asOfMs = null): array
    {
        $keys = FeatureSchema::keys($schema, $custom);
        $timeframe = new CandleTimeframe;
        $timeframe->next(0, $period);
        $asOfMs = min($asOfMs ?? PHP_INT_MAX, now()->getTimestampMs());
        $toMs = min($toMs ?? $asOfMs, $asOfMs);
        $fromMs ??= 0;
        if ($exchange === '' || $symbol === '' || $fromMs < 0 || $fromMs > $toMs) {
            throw new InvalidArgumentException('Invalid market or decision-time range.');
        }
        $id = (string) Str::uuid7();
        $directory = $this->store->directory($id);
        $temporary = $directory.'.tmp';
        // Share M2's lock: a snapshot must not capture a partially rebuilt feature series.
        $lock = Cache::lock('trademinator:features:'.hash('sha256', "$exchange|$symbol|$period"), 720);
        if (! $lock->get()) {
            throw new RuntimeException('Features or a dataset are already being built for this market and period. Retry after that build finishes.');
        }
        $file = null;
        $started = microtime(true);
        try {
            if (! mkdir($temporary, 0700, true)) {
                throw new RuntimeException('Cannot create private dataset directory.');
            }
            $file = fopen($temporary.'/rows.jsonl', 'xb');
            if ($file === false) {
                throw new RuntimeException('Cannot create dataset rows.');
            }
            // Every page observes the same source snapshot, including live candle corrections.
            if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $manifest = DB::transaction(function () use ($exchange, $symbol, $period, $definition, $schema, $keys, $fromMs, $toMs, $asOfMs, $id, $directory, $temporary, $file, $timeframe, $started) {
                $query = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                    ->where('version', FeatureEngine::VERSION)->whereBetween('available_at_ms', [$fromMs, $toMs])->orderBy('microtimestamp');
                $first = (clone $query)->first();
                if ($first === null) {
                    throw new RuntimeException('No M2 features in this range. Run trademinator:build-features first.');
                }
                $candles = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                    ->whereBetween('microtimestamp', [$first->microtimestamp, $asOfMs])->orderBy('microtimestamp')->lazy(500)->getIterator();
                $candles->rewind();
                $window = [];
                $past = $patternHistory = [];
                $history = null;
                if ($definition instanceof SemanticLabels) {
                    $historyStart = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                        ->where('microtimestamp', '<=', $first->microtimestamp)->orderByDesc('microtimestamp')
                        ->limit($definition->lookback)->pluck('microtimestamp')->last();
                    $history = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                        ->whereBetween('microtimestamp', [$historyStart ?? 0, $asOfMs])->orderBy('microtimestamp')->lazy(500)->getIterator();
                    $history->rewind();
                }
                $counts = array_fill_keys(['missing_features', 'missing_source', 'gaps', 'immature'], 0);
                if ($definition instanceof SemanticLabels) {
                    $counts['semantic_warmup'] = 0;
                }
                $labels = array_fill_keys(['buy', 'sell', 'hodl'], 0);
                $hash = hash_init('sha256');
                $count = 0;
                $firstDecision = $lastDecision = null;
                foreach ($query->lazy(500) as $feature) {
                    if (microtime(true) - $started > 540) {
                        throw new RuntimeException('Dataset build exceeded 540 seconds; use a smaller date range or horizon.');
                    }
                    $payload = $feature->payload;
                    $timestamp = $feature->microtimestamp;
                    $decision = $timeframe->next($timestamp, $period);
                    if (($payload['version'] ?? null) !== FeatureEngine::VERSION
                        || ($payload['microtimestamp'] ?? null) !== $timestamp
                        || ($payload['available_at_ms'] ?? null) !== $decision || $feature->available_at_ms !== $decision) {
                        throw new RuntimeException('M2 feature time/version mismatch; rebuild features.');
                    }
                    if ($history !== null) {
                        while ($history->valid() && $history->current()->microtimestamp <= $timestamp) {
                            $ticker = $history->current();
                            $bar = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                            $bar['microtimestamp'] = (int) $ticker->microtimestamp;
                            $this->validateCandle($bar);
                            if ($past !== [] && $timeframe->next($past[array_key_last($past)]['microtimestamp'], $period) !== $bar['microtimestamp']) {
                                $past = [];
                                $patternHistory = [];
                            }
                            $past[] = $bar;
                            $past = array_slice($past, -$definition->lookback);
                            $history->next();
                        }
                        $current = $past === [] ? null : $past[array_key_last($past)];
                        if (($current['microtimestamp'] ?? null) === $timestamp) {
                            $patternHistory[] = ['candle' => $current, 'features' => $payload['features']];
                            $patternHistory = array_slice($patternHistory, -2);
                        }
                        if (count($past) < $definition->lookback) {
                            $counts['semantic_warmup']++;

                            continue;
                        }
                    }
                    $vector = FeatureSchema::vector($payload, $keys);
                    if ($vector === null) {
                        $counts['missing_features']++;

                        continue;
                    }
                    $window = array_values(array_filter($window, fn ($bar) => $bar['microtimestamp'] >= $timestamp));
                    while ($candles->valid() && $candles->current()->microtimestamp < $timestamp) {
                        $candles->next();
                    }
                    while (count($window) <= $definition->horizon && $candles->valid()) {
                        $ticker = $candles->current();
                        $raw = json_decode($ticker->payload, true, flags: JSON_THROW_ON_ERROR);
                        $raw['microtimestamp'] = (int) $ticker->microtimestamp;
                        $window[] = $raw;
                        $candles->next();
                    }
                    if (($window[0]['microtimestamp'] ?? null) !== $timestamp) {
                        $counts['missing_source']++;

                        continue;
                    }
                    if (count($window) <= $definition->horizon
                        || $timeframe->next($window[$definition->horizon]['microtimestamp'], $period) > $asOfMs) {
                        $counts['immature']++;

                        continue;
                    }
                    $expected = $timestamp;
                    foreach ($window as $bar) {
                        if ($bar['microtimestamp'] !== $expected) {
                            $counts['gaps']++;

                            continue 2;
                        }
                        $this->validateCandle($bar);
                        $expected = $timeframe->next($expected, $period);
                    }
                    if (! isset($payload['close']) || (float) $payload['close'] !== (float) $window[0]['close']) {
                        throw new RuntimeException('Source candles changed since M2 features were built; rebuild features.');
                    }
                    $entry = $window[1];
                    $exit = $window[$definition->horizon];
                    $label = $definition instanceof SemanticLabels
                        ? $definition->label($past, $window)
                        : $definition->label((float) $entry['open'], (float) $exit['close']);
                    $row = [
                        'microtimestamp' => $timestamp, 'decision_at_ms' => $decision,
                        'entry_at_ms' => $entry['microtimestamp'], 'label_available_at_ms' => $expected,
                        'vector' => $vector, 'label' => $label['action'],
                        'entry_price' => (string) $entry['open'], 'exit_price' => (string) $exit['close'],
                        'gross_return' => $label['gross_return'], 'buy_net_return' => $label['buy_net_return'],
                        'sell_base_net_return' => $label['sell_base_net_return'],
                        'source' => ['feature_id' => $feature->getKey(), 'history_start_ms' => $payload['history_start_ms'] ?? null,
                            'context_snapshot_id' => $payload['context_snapshot_id'] ?? null,
                            'feature_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                            'candle_window_sha256' => hash('sha256', json_encode($window, JSON_THROW_ON_ERROR))],
                    ];
                    if ($definition instanceof SemanticLabels) {
                        $row['source']['semantic_history_sha256'] = hash('sha256', json_encode($past, JSON_THROW_ON_ERROR));
                        $row['semantic'] = $label['semantic'];
                        $row['candle'] = $window[0];
                        $row['patterns'] = (new PatternCatalog)->observations($patternHistory, $window, $period);
                    }
                    $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                    DatasetStore::write($file, $line);
                    hash_update($hash, $line);
                    $labels[$row['label']]++;
                    $count++;
                    $firstDecision ??= $decision;
                    $lastDecision = $decision;
                    if ($count > config('research.max_rows')) {
                        throw new RuntimeException('Dataset exceeds research.max_rows; choose a smaller --from/--to range.');
                    }
                }
                if ($count === 0) {
                    throw new RuntimeException('No eligible labelled rows: '.json_encode($counts).'. Collect more history or choose a schema with available features.');
                }
                if (! fflush($file)) {
                    throw new RuntimeException('Could not flush dataset rows.');
                }
                $manifest = [
                    'dataset_id' => $id, 'format_version' => 'm3-dataset-v1', 'created_at' => now()->toIso8601String(),
                    'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                    'feature_version' => FeatureEngine::VERSION, 'schema' => $schema, 'keys' => $keys,
                    'missing_policy' => 'drop_selected_missing', 'normalization' => 'fixed_m2_v1_no_fitting',
                    'feature_boundary_tolerance' => FeatureSchema::BOUNDARY_TOLERANCE,
                    'label_definition' => $definition->metadata(), 'from_ms' => $fromMs, 'to_ms' => $toMs, 'as_of_ms' => $asOfMs,
                    'first_decision_at_ms' => $firstDecision, 'last_decision_at_ms' => $lastDecision,
                    'rows' => $count, 'label_counts' => $labels, 'skipped' => $counts,
                    'rows_sha256' => hash_final($hash),
                ];
                $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
                if (file_put_contents($temporary.'/manifest.json', $json) !== strlen($json) || ! rename($temporary, $directory)) {
                    throw new RuntimeException('Could not publish dataset snapshot.');
                }
                DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return $manifest;
            });

            app(ActionLog::class)->write('dataset.saved', ['dataset_id' => $id,
                'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                'rows' => $manifest['rows'], 'outcome' => 'completed']);

            return $manifest;
        } catch (Throwable $error) {
            foreach ([$temporary, $directory] as $path) {
                foreach (['rows.jsonl', 'manifest.json'] as $name) {
                    if (is_file($path.'/'.$name)) {
                        unlink($path.'/'.$name);
                    }
                }
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
            throw $error;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            $lock->release();
        }
    }

    private function validateCandle(array $bar): void
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            if (! is_numeric($bar[$key] ?? null) || ! is_finite((float) $bar[$key]) || $bar[$key] < 0
                || ($key !== 'volume' && $bar[$key] == 0)) {
                throw new RuntimeException('Invalid source candle: '.$key);
            }
        }
        if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
            throw new RuntimeException('Invalid source candle bounds.');
        }
    }
}
