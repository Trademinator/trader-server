<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\ClosedCandleAggregator;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\LabelDefinition;
use App\Domain\Research\SemanticLabels;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function reconstructedTrainingBars(string $emptyMethod = 'no_trades', bool $oscillating = false): array
{
    $rows = [];
    for ($i = 0; $i < 50; $i++) {
        $open = $oscillating ? 100 + 10 * sin($i * M_PI / 5) : 100 + $i;
        $close = $oscillating ? 100 + 10 * sin(($i + 1) * M_PI / 5) : 100.5 + $i;
        $rows[] = ['microtimestamp' => 1704067200000 + $i * 900000, 'open' => (string) $open,
            'close' => (string) $close, 'high' => (string) (max($open, $close) + 2),
            'low' => (string) (min($open, $close) - 1), 'volume' => '1'];
    }
    $rows[29]['reconstruction'] = ['version' => CandleProvenance::VERSION, 'method' => 'lower_timeframe',
        'available_at_ms' => $rows[29]['microtimestamp'] + 900000, 'source_period' => '5m'];
    $price = $emptyMethod === 'next_open' ? $rows[31]['open'] : $rows[29]['close'];
    $rows[30] = ['microtimestamp' => $rows[30]['microtimestamp'], 'open' => $price, 'high' => $price,
        'low' => $price, 'close' => $price, 'volume' => '0', 'reconstruction' => [
            'version' => CandleProvenance::VERSION, 'method' => $emptyMethod,
            'source_period' => $emptyMethod === 'next_open' ? '15m' : '30m',
            'available_at_ms' => $rows[30]['microtimestamp'] + 1800000,
        ]];
    if ($emptyMethod === 'next_open') {
        $rows[30]['reconstruction']['inferred'] = true;
    }

    return $rows;
}

beforeEach(function () {
    config([
        'research.path' => sys_get_temp_dir().'/trademinator-reconstructed-'.Str::uuid7(),
        'exchange_fees.taker_overrides.bitso.rate' => 0.0,
        'intelligence.min_horizon_distance_observations' => 1,
    ]);
});

afterEach(function () {
    File::deleteDirectory(config('research.path'));
});

it('excludes a decision until reconstruction evidence is available and preserves provenance in subsequent features', function (string $emptyMethod) {
    $this->travelTo('2024-01-02 UTC');
    $bars = reconstructedTrainingBars($emptyMethod);
    $rows = iterator_to_array((new FeatureEngine)->rows($bars, '15m', now()->getTimestampMs()));
    $keys = FeatureSchema::keys('core');

    expect(FeatureSchema::vector($rows[29], $keys))->not->toBeNull();
    expect(FeatureSchema::vector($rows[30], $keys))->toBeNull();
    expect(FeatureSchema::vector($rows[31], $keys))->not->toBeNull();
    expect($rows[31]['reconstruction_counts'])->toBe(['lower_timeframe' => 1, $emptyMethod => 1]);
    expect($rows[30]['source_available_at_ms'])->toBe(1704096000000);
})->with(['no_trades', 'next_open']);

it('preserves the evidence availability barrier and provenance when resuming an indicator checkpoint', function () {
    $this->travelTo('2024-01-02 UTC');
    $bars = reconstructedTrainingBars();
    $checkpoint = null;
    $first = iterator_to_array((new FeatureEngine)->rows(array_slice($bars, 0, 31), '15m', now()->getTimestampMs(), 10,
        checkpointCallback: function ($state) use (&$checkpoint) {
            $checkpoint = $state;
        }));
    $second = iterator_to_array((new FeatureEngine)->rows(array_slice($bars, 31), '15m', now()->getTimestampMs(), 10, $checkpoint));

    expect($checkpoint['source_available_at_ms'])->toBe(1704096000000);
    expect($second[0]['reconstruction_counts'])->toBe(['lower_timeframe' => 1, 'no_trades' => 1]);
    expect(FeatureSchema::vector($first[30], FeatureSchema::keys('core')))->toBeNull();
    expect(FeatureSchema::vector($second[0], FeatureSchema::keys('core')))->not->toBeNull();
});

it('freezes reconstruction provenance and excludes premature decisions from an immutable dataset', function () {
    $this->travelTo('2024-01-02 UTC');
    $bars = reconstructedTrainingBars();
    $tickers = app(TickerRepository::class);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', $bars);
    app(FeatureBuilder::class)->build('bitso', 'ATOM/USD', '15m');
    $manifest = app(DatasetSnapshotBuilder::class)->build('bitso', 'ATOM/USD', '15m', new LabelDefinition(2));
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $byTime = array_column($rows, null, 'microtimestamp');

    expect($byTime)->toHaveKey($bars[29]['microtimestamp'])->not->toHaveKey($bars[30]['microtimestamp']);
    expect($byTime[$bars[31]['microtimestamp']]['source']['reconstruction']['feature_history_counts'])
        ->toBe(['lower_timeframe' => 1, 'no_trades' => 1]);
    expect($manifest['skipped']['unavailable_evidence'])->toBe(1);
    expect($manifest['reconstruction']['rows_with_reconstructed_candle'])->toBe(1);
    expect($manifest['reconstruction']['methods']['no_trades'])->toBeGreaterThan(0);

    $native = $bars[30];
    unset($native['reconstruction']);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [$native]);
    [, $frozen] = app(DatasetStore::class)->load($manifest['dataset_id']);
    expect($frozen)->toBe($rows);
});

it('does not publish a label before the parent evidence used in its future window is available', function () {
    $this->travelTo('2024-01-02 UTC');
    $bars = reconstructedTrainingBars();
    app(TickerRepository::class)->saveTickers('bitso', 'ATOM/USD', '15m', $bars);
    app(FeatureBuilder::class)->build('bitso', 'ATOM/USD', '15m');
    $manifest = app(DatasetSnapshotBuilder::class)->build('bitso', 'ATOM/USD', '15m', new LabelDefinition(1));
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $byTime = array_column($rows, null, 'microtimestamp');

    expect($byTime[$bars[29]['microtimestamp']]['label_available_at_ms'])->toBe(1704096000000);
    $early = app(DatasetSnapshotBuilder::class)->build('bitso', 'ATOM/USD', '15m', new LabelDefinition(1),
        asOfMs: 1704095100000);
    [, $earlyRows] = app(DatasetStore::class)->load($early['dataset_id']);
    expect(array_column($earlyRows, 'microtimestamp'))->not->toContain($bars[29]['microtimestamp']);
});

it('delays pattern outcomes until their reconstructed source evidence is available', function () {
    $bars = reconstructedTrainingBars();
    $history = [['candle' => $bars[29], 'features' => ['candle.body' => 0.8, 'candle.direction' => -1, 'trend.direction' => -1]]];

    $patterns = (new PatternCatalog)->observations($history, array_slice($bars, 29, 3), '15m');

    expect($patterns)->not->toBeEmpty();
    expect($patterns[0]['label_available_at_ms'])->toBe(1704096000000);
});

it('propagates reconstructed source availability into derived larger candles', function () {
    $bars = reconstructedTrainingBars();
    $price = $bars[28]['close'];
    $bars[29] = ['microtimestamp' => $bars[29]['microtimestamp'], 'open' => $price, 'high' => $price, 'low' => $price,
        'close' => $price, 'volume' => '0', 'reconstruction' => ['version' => CandleProvenance::VERSION,
            'method' => 'no_trades', 'available_at_ms' => 1704096000000, 'source_period' => '1h']];

    $derived = iterator_to_array((new ClosedCandleAggregator)->rows($bars, '15m', '30m', 1704114000000));
    $byTime = array_column($derived, null, 'microtimestamp');

    expect($byTime[1704092400000]['reconstruction']['available_at_ms'])->toBe(1704096000000);
    expect($byTime[1704092400000]['reconstruction']['method'])->toBe('lower_timeframe');
});

it('includes reconstruction use in the published KNN report', function (string $emptyMethod) {
    $this->travelTo('2024-01-02 UTC');
    config(['intelligence.path' => config('research.path').'/models', 'intelligence.patterns.enabled' => false,
        'intelligence.knn.min_train_size' => 5, 'intelligence.knn.test_size' => 2,
        'intelligence.knn.min_validation_rows' => 1]);
    app(TickerRepository::class)->saveTickers('bitso', 'ATOM/USD', '15m', reconstructedTrainingBars($emptyMethod, true));
    app(FeatureBuilder::class)->build('bitso', 'ATOM/USD', '15m');
    $manifest = app(DatasetSnapshotBuilder::class)->build('bitso', 'ATOM/USD', '15m', new SemanticLabels(2, 3));

    $model = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($model['training_data']['reconstruction']['rows_using_reconstructed_history'])->toBeGreaterThan(0);
    expect($model['training_data']['reconstruction']['methods'])->toHaveKeys(['lower_timeframe', $emptyMethod]);
})->with(['no_trades', 'next_open']);

it('keeps carried empty-interval prices out of larger candle trade prices', function (bool $emptyFirst) {
    $at = 1704067200000;
    $price = $emptyFirst ? '11' : '52';
    $empty = ['microtimestamp' => $at + ($emptyFirst ? 0 : 900000), 'open' => $price, 'high' => $price,
        'low' => $price, 'close' => $price, 'volume' => '0', 'reconstruction' => [
            'version' => CandleProvenance::VERSION, 'method' => 'no_trades', 'source_period' => '30m',
            'available_at_ms' => $at + 1800000,
        ]];
    $traded = ['microtimestamp' => $at + ($emptyFirst ? 900000 : 0), 'open' => '50', 'high' => '55',
        'low' => '49', 'close' => '52', 'volume' => '4'];

    $derived = iterator_to_array((new ClosedCandleAggregator)->rows($emptyFirst ? [$empty, $traded] : [$traded, $empty],
        '15m', '30m', $at + 1800000));

    expect($derived[0])->toMatchArray(['microtimestamp' => $at, 'open' => '50', 'high' => '55',
        'low' => '49', 'close' => '52', 'volume' => '4.00']);
    expect($derived[0]['reconstruction']['method'])->toBe('lower_timeframe');
})->with([true, false]);

it('never makes next-open fills available before the following candle closes', function () {
    $bar = reconstructedTrainingBars('next_open')[30];
    $bar['reconstruction']['available_at_ms'] = $bar['microtimestamp'] + 900000;

    expect(CandleProvenance::availableAt($bar, '15m'))->toBe(1704096000000);
});
