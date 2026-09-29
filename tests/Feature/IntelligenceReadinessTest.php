<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-readiness-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'queue.default' => 'database', 'intelligence.patterns.enabled' => false]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function readinessFeatures(int $count, string $period = '1m', string $version = FeatureEngine::VERSION): void
{
    $timeframe = new CandleTimeframe;
    $timestamp = IntelligenceFixtures::START;
    $values = array_fill_keys(FeatureSchema::keys('core'), 0.5);
    $values['trend.direction'] = $values['candle.direction'] = 0;
    for ($i = 0; $i < $count; $i++) {
        $available = $timeframe->next($timestamp, $period);
        DB::table('market_features')->insert(['feature_id' => (string) Str::uuid7(),
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period, 'version' => $version,
            'microtimestamp' => $timestamp, 'available_at_ms' => $available,
            'payload' => json_encode(['version' => $version, 'microtimestamp' => $timestamp,
                'available_at_ms' => $available, 'features' => $values]), 'created_at' => now(), 'updated_at' => now()]);
        $timestamp = $available;
    }
}

function readiness(?array $report = null, ?string $period = '1m', ?MarketFeed $feed = null): array
{
    return app(IntelligenceReadiness::class)->describe('kraken', 'BTC/USD', $period, $feed, $report, WeightedKnn::abstain('no_model'));
}

it('shows configured row requirements and a conditional data ETA excluding open and immature features', function () {
    $this->travelTo('2024-01-01 03:20:00 UTC');
    readinessFeatures(201);
    readinessFeatures(201, version: 'old-version');

    $progress = readiness();

    expect($progress['minimum'])->toBe(405);
    expect($progress['full_fold_minimum'])->toBe(468);
    expect($progress['history']['closed'])->toBe(200);
    expect($progress['history']['potential'])->toBe(187);
    expect($progress['history']['immature'])->toBe(13);
    expect($progress['eta']->format('Y-m-d H:i:s'))->toBe('2024-01-01 06:58:00');
    expect($progress['next_training']->format('Y-m-d H:i:s'))->toBe('2024-01-01 04:00:00');
});

it('makes the minimum match actual chronological tuning at the boundary', function (int $count, string $status) {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.knn.train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1]);
    $manifest = IntelligenceFixtures::snapshot($count);

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $progress = readiness($report);

    expect($progress['minimum'])->toBe(57);
    expect($report['status'])->toBe($status);
    expect($progress['source']['usable_rows'])->toBe($count);
})->with([[56, 'abstaining'], [57, 'ready']]);

it('suppresses misleading ETAs for stale or missing feature history', function () {
    $this->travelTo('2024-01-01 03:20:00 UTC');
    expect(readiness()['eta'])->toBeNull();
    readinessFeatures(10);

    $progress = readiness();

    expect($progress['eta'])->toBeNull();
    expect($progress['issues'])->toContain('Feature collection is stale. Check the collector, scheduler and default queue worker.');
});

it('names missing selected features and withholds a timer for schema warmup', function () {
    $this->travelTo('2024-01-01 00:02:00 UTC');
    readinessFeatures(2);
    config(['intelligence.schema' => 'technical']);

    $progress = readiness();

    expect($progress['history']['missing_keys'])->toBe(['return.24h', 'return.7d', 'return.30d']);
    expect($progress['eta'])->toBeNull();
    expect($progress['history']['potential'])->toBe(0);
});

it('reports gaps and impossible row caps instead of extending the wait indefinitely', function () {
    $this->travelTo('2024-01-01 00:20:00 UTC');
    readinessFeatures(20);
    DB::table('market_features')->where('microtimestamp', IntelligenceFixtures::START + 10 * 60000)->delete();
    config(['intelligence.max_rows' => 100]);

    $progress = readiness();

    expect($progress['history']['gaps'])->toBe(1);
    expect($progress['eta'])->toBeNull();
    expect(implode(' ', $progress['issues']))->toContain('max_rows cap is below');
});

it('keeps unknown period, disabled scheduling and a nonpersistent queue explicit', function () {
    config(['intelligence.enabled' => false, 'queue.default' => 'sync']);

    $progress = readiness(period: null);

    expect($progress['history'])->toBeNull();
    expect($progress['next_training'])->toBeNull();
    expect(implode(' ', $progress['issues']))->toContain('disabled')->toContain('persistent queue');
});

it('uses calendar-month boundaries and the configured scheduler timezone', function () {
    $this->travelTo('2024-09-01 00:00:00 UTC');
    readinessFeatures(8, '1M');
    config(['intelligence.horizon' => 2, 'intelligence.knn.train_size' => 3,
        'intelligence.knn.min_validation_rows' => 1, 'app.schedule_timezone' => 'America/Toronto']);

    $progress = readiness(period: '1M');

    expect($progress['minimum'])->toBe(10);
    expect($progress['history']['potential'])->toBe(5);
    expect($progress['eta']->format('Y-m-d H:i:s'))->toBe('2025-02-01 00:00:00');
    expect($progress['next_training']->format('Y-m-d H:i:s'))->toBe('2024-09-02 08:00:00');
});

it('preserves legacy model counts without confusing the retained pool with total history', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot(227);
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    unset($report['training_data']);

    $progress = readiness($report);

    expect($progress['source']['source_rows'])->toBe(227);
    expect($progress['source']['usable_rows'])->toBe(227);
    expect($progress['settings']['train_size'])->toBe(250);
    expect($progress['tuning']['gates'][0]['passed'])->toBeFalse();
    $report['pattern_keys'] = ['pattern.bullish_engulfing.probability'];
    expect(readiness($report)['source']['usable_rows'])->toBeNull();
});
