<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\AutomaticSchemaSelection;
use App\Domain\Intelligence\IntelligenceNotReady;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Research\FeatureSchema;
use App\Models\MarketFeature;
use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/automatic-schema-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.context_fallback' => 'none', 'intelligence.horizon' => 2, 'intelligence.lookback' => 3,
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.patterns.enabled' => false,
        'intelligence.min_horizon_distance_observations' => 1,
        'human_training.enabled' => false, 'lead_lag.enabled' => false]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

/** Oscillating source prices provide Action pivots; feature availability, not model quality, is under test. */
function automaticSchemaHistory(int $fullFrom = 150, int $count = 160): int
{
    $start = IntelligenceFixtures::START;
    for ($i = 0; $i < $count; $i++) {
        $at = $start + $i * 60000;
        $features = array_fill_keys(FeatureSchema::keys('full'), 0.5);
        $features['trend.direction'] = $features['candle.direction'] = 0;
        if ($i < $fullFrom) {
            foreach (array_diff(FeatureSchema::keys('full'), FeatureEngine::KEYS) as $key) {
                $features[$key] = null;
            }
        }
        $phase = $i % 20;
        $close = $phase <= 10 ? 100 + 2 * $phase : 100 + 2 * (20 - $phase);
        $previousPhase = ($i + 19) % 20;
        $open = $previousPhase <= 10 ? 100 + 2 * $previousPhase : 100 + 2 * (20 - $previousPhase);
        MarketFeature::query()->forceCreate(['feature_id' => (string) Str::uuid7(), 'exchange' => 'kraken',
            'symbol' => 'BTC/USD', 'period' => '1m', 'microtimestamp' => $at, 'available_at_ms' => $at + 60000,
            'version' => FeatureEngine::VERSION, 'payload' => ['version' => FeatureEngine::VERSION,
                'microtimestamp' => $at, 'available_at_ms' => $at + 60000, 'close' => $close, 'features' => $features]]);
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'microtimestamp' => $at,
            'payload' => json_encode(['open' => (string) $open, 'high' => (string) (max($open, $close) + 0.2),
                'low' => (string) (min($open, $close) - 0.2), 'close' => (string) $close, 'volume' => '1'])]);
    }

    return $start + $count * 60000;
}

function inspectAutomaticSchema(int $asOf): array
{
    return app(AutomaticSchemaSelection::class)->inspect('kraken', 'BTC/USD', '1m',
        IntelligenceFixtures::START, $asOf, $asOf, 2, microtime(true) + 30);
}

it('selects technical inputs from availability without looking at model scores or publishing anything', function () {
    $asOf = automaticSchemaHistory();

    $selection = inspectAutomaticSchema($asOf);

    expect($selection)->toMatchArray(['requested_schema' => 'full', 'effective_schema' => 'technical',
        'reason' => 'insufficient_full_feature_history', 'validation_performed' => false]);
    expect($selection['complete_feature_rows'])->toBe(['technical' => 160, 'full' => 10]);
    expect($selection['potential_history']['full']['potential_mature_rows'])->toBe(8);
    expect($selection['potential_history']['technical']['potential_mature_rows'])->toBe(158);
    expect($selection['potential_history']['technical']['tuning_rows_after_purge'])->toBe(124);
    $this->assertDatabaseCount('research_datasets', 0);
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('keeps full when its available history can support tuning and holdout', function () {
    $selection = inspectAutomaticSchema(automaticSchemaHistory(fullFrom: 0));

    expect($selection['effective_schema'])->toBe('full');
    expect($selection['reason'])->toBe('full_feature_history_sufficient');
});

it('does not claim that an insufficient technical history is a useful fallback', function () {
    $selection = inspectAutomaticSchema(automaticSchemaHistory(count: 20));

    expect($selection['effective_schema'])->toBe('full');
    expect($selection['reason'])->toBe('insufficient_both_feature_histories');
});

it('stops before dataset construction when neither full nor technical history is trainable', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory(count: 20);

    try {
        app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full', contextFallback: 'technical');
        $this->fail('Expected intelligence build to report insufficient history.');
    } catch (IntelligenceNotReady $error) {
        expect($error->diagnostics['reason'])->toBe('insufficient_both_feature_histories')
            ->and($error->diagnostics['potential_history']['full']['sufficient'])->toBeFalse()
            ->and($error->diagnostics['potential_history']['technical']['sufficient'])->toBeFalse();
    }

    $this->assertDatabaseCount('research_datasets', 0);
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('honors source availability and reports individual missing inputs', function () {
    $asOf = automaticSchemaHistory();
    $row = MarketFeature::query()->orderBy('microtimestamp')->first();
    $payload = $row->payload;
    $payload['source_available_at_ms'] = $row->available_at_ms + 1;
    $row->forceFill(['payload' => $payload])->save();
    $other = MarketFeature::query()->orderBy('microtimestamp')->skip(1)->first();
    $payload = $other->payload;
    $payload['features']['return.30d'] = null;
    $other->forceFill(['payload' => $payload])->save();

    $selection = inspectAutomaticSchema($asOf);

    expect($selection['complete_feature_rows']['technical'])->toBe(158);
    expect($selection['missing_by_key']['return.30d'])->toBe(1);
    expect($selection['missing_by_key']['context.btc_dominance'])->toBe(150);
});

it('fails closed on invalid numeric features rather than falling back around corruption', function () {
    $asOf = automaticSchemaHistory(fullFrom: 0);
    $row = MarketFeature::query()->first();
    $payload = $row->payload;
    $payload['features']['context.btc_dominance'] = 2;
    $row->forceFill(['payload' => $payload])->save();

    expect(fn () => inspectAutomaticSchema($asOf))->toThrow(InvalidArgumentException::class, 'Feature outside');
});

it('applies the opt-in policy before building a single immutable dataset and does not force readiness', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory();

    $this->artisan('trademinator:knn-build', ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--schema' => 'full', '--context-fallback' => 'technical'])->assertSuccessful();

    $model = app(ModelStore::class)->current('kraken', 'BTC/USD', '1m');
    expect($model['outcome']['schema'])->toBe('technical');
    expect($model['keys'])->toBe(FeatureEngine::KEYS);
    expect($model['outcome']['schema_selection']['requested_schema'])->toBe('full');
    expect($model['outcome']['status'])->toBe('abstaining');
    expect($model['settings']['min_semantic_precision'])->toBe(0.55);
    expect($model['settings']['max_contradiction_rate'])->toBe(0.05);
    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseCount('intelligence_models', 1);
});

it('never retries a technically sufficient full build after K selection fails', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory(fullFrom: 0);

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full', contextFallback: 'technical');

    expect($report['outcome']['schema'])->toBe('full');
    expect($report['outcome']['reason'])->toBe('no_eligible_k');
    expect($report['outcome']['history_status'])->toBe('tuning_evaluated');
    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
});

it('keeps strict full behavior by default and supports an explicit override of configuration', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory();
    expect(fn () => app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full'))
        ->toThrow(RuntimeException::class, 'No eligible labelled rows');
    config(['intelligence.context_fallback' => 'technical']);
    $configured = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full');
    expect(fn () => app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full', contextFallback: 'none'))
        ->toThrow(RuntimeException::class, 'No eligible labelled rows');

    expect($configured['outcome']['schema'])->toBe('technical');
});

it('does not reselect an explicitly frozen dataset when fallback is configured', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['intelligence.context_fallback' => 'technical']);
    $manifest = IntelligenceFixtures::snapshot();

    $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', dataset: $manifest['dataset_id']);

    expect($report['dataset_id'])->toBe($manifest['dataset_id']);
    expect($report['outcome']['schema_selection']['reason'])->toBe('frozen_dataset');
    $this->assertDatabaseCount('research_datasets', 1);
});

it('distinguishes opted-in generation results and remains idempotent on redelivery', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory();
    $builder = app(MarketIntelligence::class);
    $generation = hash('sha256', 'same-week');
    expect(fn () => $builder->build('kraken', 'BTC/USD', '1m', schema: 'full', generation: $generation))
        ->toThrow(RuntimeException::class, 'No eligible labelled rows');
    $fallback = $builder->build('kraken', 'BTC/USD', '1m', schema: 'full', generation: $generation, contextFallback: 'technical');
    $repeat = $builder->build('kraken', 'BTC/USD', '1m', schema: 'full', generation: $generation, contextFallback: 'technical');

    expect($repeat['model_id'])->toBe($fallback['model_id']);
    $this->assertDatabaseCount('intelligence_models', 1);
});

it('shares the feature lock and never releases the caller-owned lock', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory();
    $lock = Cache::lock('trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1m'), 720);
    $lock->get();
    try {
        expect(fn () => app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full', contextFallback: 'technical'))
            ->toThrow(RuntimeException::class, 'already being built');
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        $report = app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full',
            featureLockOwner: $lock->owner(), contextFallback: 'technical');
        expect($report['outcome']['schema'])->toBe('technical');
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('rejects invalid policy combinations without creating artifacts', function (array $options) {
    $this->artisan('trademinator:knn-build', ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', ...$options])
        ->assertFailed();
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('research_datasets', 0);
})->with([
    'unknown policy' => [['--schema' => 'full', '--context-fallback' => 'core']],
    'not full' => [['--schema' => 'technical', '--context-fallback' => 'technical']],
    'frozen dataset' => [['--dataset' => 'anything', '--context-fallback' => 'technical']],
]);

it('stops on an exhausted preflight budget without writing a dataset', function () {
    expect(fn () => app(AutomaticSchemaSelection::class)->inspect('kraken', 'BTC/USD', '1m',
        1704067200000, 1704067260000, 1704067260000, 2, microtime(true) - 1))
        ->toThrow(RuntimeException::class, 'build budget');
    $this->assertDatabaseCount('research_datasets', 0);
});

it('rejects stale borrowed locks before reading or publishing data', function () {
    $this->travelTo('2024-01-01 04:00:00 UTC');
    automaticSchemaHistory();

    expect(fn () => app(MarketIntelligence::class)->build('kraken', 'BTC/USD', '1m', schema: 'full',
        featureLockOwner: 'not-the-owner', contextFallback: 'technical'))
        ->toThrow(RuntimeException::class, 'lock ownership');
    $this->assertDatabaseCount('research_datasets', 0);
});
