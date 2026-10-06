<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $this->trainer = User::factory()->create();
    $path = sys_get_temp_dir().'/human-projection-'.Str::uuid7();
    config(['research.path' => $path.'/datasets', 'intelligence.path' => $path.'/models',
        'operations.owner_uuid' => $this->trainer->user_id, 'operations.owner_uuids' => [],
        'human_training.trainer_uuids' => [], 'human_training.enabled' => true, 'human_training.candle_enabled' => true,
        'human_training.chart_candles' => 90, 'human_training.candle_min_samples' => 50,
        'human_training.min_reviewers' => 1, 'human_training.min_agreement' => 0.67]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function projectionDataset(array $manifest, array $rows): array
{
    $bytes = implode('', array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows));
    $manifest['rows'] = count($rows);
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    File::ensureDirectoryExists($path, 0700);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    file_put_contents($path.'/rows.jsonl', $bytes);
    DB::table('research_datasets')->updateOrInsert(['dataset_id' => $manifest['dataset_id']],
        ['manifest' => json_encode($manifest), 'created_at' => now()]);

    return $manifest;
}

/** Old core annotations, current technical vectors, and deliberately sparse full data. */
function projectionFixture(User $trainer, string $version = 'm2-v5', int $count = 60): array
{
    $source = IntelligenceFixtures::snapshot($count);
    [, $rows] = app(DatasetStore::class)->load($source['dataset_id']);
    $source['feature_version'] = $version;
    $source['schema'] = 'core';
    $source['keys'] = FeatureSchema::keys('core');
    $bars = [];
    for ($i = -89; $i < $count; $i++) {
        $time = IntelligenceFixtures::START + $i * 60000;
        $bar = ['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'];
        $bars[$time] = ['time' => intdiv($time, 1000), ...$bar];
        DB::table('tickers')->insert(['ticker_id' => (string) Str::uuid7(), 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => $time, 'payload' => json_encode($bar)]);
    }
    $snapshots = [];
    foreach ($rows as $i => &$row) {
        $old = $current = [];
        foreach (FeatureEngine::KEYS as $key) {
            $direction = in_array($key, ['candle.direction', 'trend.direction']);
            $old[$key] = $direction ? 0 : 0.5;
            $current[$key] = $direction ? ($i % 3 - 1) : (($i % 3) / 2);
        }
        $oldPayload = ['version' => $version, 'microtimestamp' => $row['microtimestamp'],
            'available_at_ms' => $row['decision_at_ms'], 'features' => $old, 'close' => 10.0];
        $row['vector'] = array_values(array_intersect_key($old, array_flip($source['keys'])));
        $row['source'] = ['feature_sha256' => hash('sha256', json_encode($oldPayload))];
        $row['candle'] = ['microtimestamp' => $row['microtimestamp'], 'open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'];
        $payload = array_replace(HumanTrainingSnapshot::factory()->make()->payload, [
            'feature_version' => $version, 'microtimestamp' => $row['microtimestamp'], 'decision_at_ms' => $row['decision_at_ms'],
            'keys' => $source['keys'], 'features' => array_combine($source['keys'], $row['vector']),
            'vector' => NormalizedVector::from($row['vector'], $source['keys']),
            'feature_sha256' => $row['source']['feature_sha256'],
            'series' => array_values(array_slice($bars, $i, 90, true)),
        ]);
        $snapshot = HumanTrainingSnapshot::factory()->create(['dataset_id' => $source['dataset_id'], 'payload' => $payload]);
        HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id,
            'action' => ['buy', 'hold', 'sell'][$i % 3], 'created_at' => now(), 'updated_at' => now()]);
        $snapshots[] = $snapshot;
        DB::table('market_features')->insert(['feature_id' => (string) Str::uuid7(),
            'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m', 'microtimestamp' => $row['microtimestamp'],
            'available_at_ms' => $row['decision_at_ms'], 'version' => FeatureEngine::VERSION,
            'payload' => json_encode(['version' => FeatureEngine::VERSION, 'microtimestamp' => $row['microtimestamp'],
                'available_at_ms' => $row['decision_at_ms'], 'features' => $current, 'close' => 10.0]),
            'created_at' => now(), 'updated_at' => now()]);
    }
    unset($row);
    $source = projectionDataset($source, $rows);
    $target = [...$source, 'dataset_id' => (string) Str::uuid7(), 'feature_version' => FeatureEngine::VERSION,
        'schema' => 'full', 'keys' => FeatureSchema::keys('full')];
    $targetRows = array_slice($rows, -2);
    foreach ($targetRows as &$row) {
        $row['vector'] = array_fill(0, count($target['keys']), 0.5);
    }
    unset($row);
    $target = projectionDataset($target, $targetRows);

    return [$source, $target, $snapshots];
}

function projectionAudit(array $target): array
{
    return app(HumanCandleKnn::class)->audit($target, microtime(true) + 30);
}

it('reuses old core annotations with current technical inputs without CoinGecko or old feature records', function (string $version) {
    [$source, $target, $snapshots] = projectionFixture($this->trainer, $version);
    $before = DB::table('human_training_snapshots')->orderBy('snapshot_id')->get()->toJson();
    $labels = DB::table('human_candle_labels')->orderBy('candle_label_id')->get()->toJson();
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(60)->and($audit['input_keys'])->toBe(FeatureEngine::KEYS)
        ->and($audit['class_counts'])->toBe(['buy' => 20, 'hold' => 20, 'sell' => 20])
        ->and($audit['annotation_diagnostics']['projected_candles'])->toBe(60)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe([])
        ->and($audit['validation_performed'])->toBeFalse();
    expect(DB::table('human_training_snapshots')->orderBy('snapshot_id')->get()->toJson())->toBe($before)
        ->and(DB::table('human_candle_labels')->orderBy('candle_label_id')->get()->toJson())->toBe($labels);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('research_datasets', 2);
})->with(['m2-v3', 'm2-v5', FeatureEngine::VERSION]);

it('trains and predicts using the new vector while retaining original provenance', function () {
    [$source, $target] = projectionFixture($this->trainer, 'm2-v5', 240);
    $bundle = app(HumanCandleKnn::class)->train($target, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['status'])->toBe('validated')->and($bundle['samples'])->toBe(240)
        ->and($bundle['knowledge'][0]['vector'])->toBe(array_fill(0, 18, 0.0))
        ->and($bundle['knowledge'][0]['label'])->toBe('buy')
        ->and($bundle['label_provenance_sha256'])->toHaveLength(64);
    $signal = app(HumanCandleKnn::class)->predict($bundle,
        ['features' => array_combine(FeatureEngine::KEYS, array_map(fn ($key) => str_ends_with($key, '.direction') ? -1 : 0.0, FeatureEngine::KEYS))],
        $bundle['available_at_ms'] + 60000);
    expect($signal['action'])->toBe('buy');
});

it('rejects changes anywhere in the reviewed chart even when all selected indicators are unchanged', function () {
    [$source, $target] = projectionFixture($this->trainer);
    DB::table('tickers')->where('microtimestamp', IntelligenceFixtures::START - 89 * 60000)
        ->update(['payload' => json_encode(['open' => '10', 'high' => '12', 'low' => '9', 'close' => '10', 'volume' => '1'])]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe(['reviewed_chart_changed' => 1]);
});

it('accepts equivalent decimal encodings without rounding away a price change', function () {
    [$source, $target] = projectionFixture($this->trainer);
    $timestamp = IntelligenceFixtures::START - 89 * 60000;
    DB::table('tickers')->where('microtimestamp', $timestamp)
        ->update(['payload' => json_encode(['open' => '1e1', 'high' => '11.000', 'low' => '9.0', 'close' => '10.00', 'volume' => '1.0'])]);
    expect(projectionAudit($target)['samples'])->toBe(60);
    DB::table('tickers')->where('microtimestamp', $timestamp)
        ->update(['payload' => json_encode(['open' => '10', 'high' => '11.000000000000000001', 'low' => '9', 'close' => '10', 'volume' => '1'])]);
    expect(projectionAudit($target)['samples'])->toBe(59);
});

it('reports missing current long return inputs instead of inventing zeroes', function () {
    [$source, $target] = projectionFixture($this->trainer);
    $feature = DB::table('market_features')->orderBy('microtimestamp')->first();
    $payload = json_decode($feature->payload, true);
    unset($payload['features']['return.30d']);
    DB::table('market_features')->where('feature_id', $feature->feature_id)->update(['payload' => json_encode($payload)]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe(['missing_current_features' => 1]);
});

it('rejects features that use evidence unavailable at the annotated decision', function () {
    [$source, $target] = projectionFixture($this->trainer);
    $feature = DB::table('market_features')->orderBy('microtimestamp')->first();
    $payload = json_decode($feature->payload, true);
    $payload['source_available_at_ms'] = $payload['available_at_ms'] + 60000;
    DB::table('market_features')->where('feature_id', $feature->feature_id)->update(['payload' => json_encode($payload)]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)->and($audit['annotation_diagnostics']['excluded'])->toBe(['unavailable_evidence' => 1]);
});

it('fails loudly for corrupted source rows or snapshot checksums', function (string $part) {
    [$source, $target, $snapshots] = projectionFixture($this->trainer);
    if ($part === 'snapshot') {
        DB::table('human_training_snapshots')->where('snapshot_id', $snapshots[0]->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);
    } else {
        $path = app(DatasetStore::class)->directory($source['dataset_id']).'/rows.jsonl';
        file_put_contents($path, str_replace('0.5', '0.6', file_get_contents($path)));
    }
    expect(fn () => projectionAudit($target))->toThrow($part === 'snapshot' ? LogicException::class : RuntimeException::class);
})->with(['snapshot', 'rows']);

it('exposes a read-only command and checks the requested market', function () {
    [$source, $target] = projectionFixture($this->trainer);
    $this->artisan('trademinator:human-candle-audit', ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--dataset' => $target['dataset_id']])->expectsOutputToContain('"samples": 60')->assertSuccessful();
    $this->artisan('trademinator:human-candle-audit', ['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--dataset' => $target['dataset_id']])->expectsOutputToContain('does not match')->assertFailed();
    $this->assertDatabaseCount('intelligence_models', 0);
});

it('rejects a missing chart bar and a newly filled historical gap', function (string $change) {
    [$source, $target, $snapshots] = projectionFixture($this->trainer);
    $timestamp = IntelligenceFixtures::START - 89 * 60000;
    if ($change === 'missing') {
        DB::table('tickers')->where('microtimestamp', $timestamp)->delete();
    } else {
        // This authenticated original chart had a leading gap; canonical history
        // now contains that bar. Reuse must not silently change what was reviewed.
        $snapshot = $snapshots[0];
        $payload = $snapshot->payload;
        array_shift($payload['series']);
        DB::table('human_training_snapshots')->where('snapshot_id', $snapshot->snapshot_id)->update([
            'payload' => json_encode($payload), 'sha256' => HumanTrainingSnapshot::digest($payload),
        ]);
    }
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe(['reviewed_chart_changed' => 1]);
})->with(['missing', 'filled']);

it('preserves the original longer chart extent after the configured chart window shrinks', function () {
    [$source, $target] = projectionFixture($this->trainer);
    config(['human_training.chart_candles' => 20]);
    expect(projectionAudit($target)['samples'])->toBe(60);
    DB::table('tickers')->where('microtimestamp', IntelligenceFixtures::START - 89 * 60000)
        ->update(['payload' => json_encode(['open' => '10', 'high' => '12', 'low' => '9', 'close' => '10', 'volume' => '1'])]);
    expect(projectionAudit($target)['samples'])->toBe(59);
});

it('rejects reconstructed chart evidence that was not available at the decision', function () {
    [$source, $target] = projectionFixture($this->trainer);
    $timestamp = IntelligenceFixtures::START - 89 * 60000;
    $bar = ['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1',
        'reconstruction' => ['version' => 'isolated-candle-v1', 'method' => 'next_open',
            'available_at_ms' => IntelligenceFixtures::START + 120000]];
    DB::table('tickers')->where('microtimestamp', $timestamp)->update(['payload' => json_encode($bar)]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe(['unavailable_evidence' => 1]);
});

it('keeps authorization and annotation-cutoff exclusions separate from compatibility', function () {
    [$source, $target, $snapshots] = projectionFixture($this->trainer);
    $other = User::factory()->create();
    DB::table('human_candle_labels')->where('snapshot_id', $snapshots[0]->snapshot_id)->update(['trainer_id' => $other->user_id]);
    DB::table('human_candle_labels')->where('snapshot_id', $snapshots[1]->snapshot_id)->update(['updated_at' => now()->addMinute()]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(58)
        ->and($audit['annotation_diagnostics']['prefiltered_snapshots']['unauthorized_trainer'])->toBe(1)
        ->and($audit['annotation_diagnostics']['prefiltered_snapshots']['after_annotation_cutoff'])->toBe(1);
});

it('does not let a recomputed snapshot checksum bypass its original frozen vector', function () {
    [$source, $target, $snapshots] = projectionFixture($this->trainer);
    $snapshot = $snapshots[0];
    $payload = $snapshot->payload;
    $payload['features']['candle.body'] = 0.123;
    DB::table('human_training_snapshots')->where('snapshot_id', $snapshot->snapshot_id)->update([
        'payload' => json_encode($payload), 'sha256' => HumanTrainingSnapshot::digest($payload),
    ]);
    $audit = projectionAudit($target);
    expect($audit['samples'])->toBe(59)
        ->and($audit['annotation_diagnostics']['excluded'])->toBe(['original_snapshot_mismatch' => 1]);
});

it('counts a candle once and retains the newest compatible snapshot and its original label identity', function () {
    [$source, $target, $snapshots] = projectionFixture($this->trainer, 'm2-v5', 240);
    $newer = HumanTrainingSnapshot::factory()->create([
        'dataset_id' => $source['dataset_id'], 'payload' => $snapshots[0]->payload,
        'snapshot_key' => hash('sha256', (string) Str::uuid7()),
    ]);
    $label = HumanCandleLabel::factory()->create(['snapshot_id' => $newer->snapshot_id,
        'trainer_id' => $this->trainer->user_id, 'action' => 'buy', 'updated_at' => now()]);
    $bundle = app(HumanCandleKnn::class)->train($target, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(240)
        ->and($bundle['annotation_diagnostics']['accepted_snapshots'])->toBe(241)
        ->and($bundle['annotation_diagnostics']['duplicate_eligible_snapshots'])->toBe(1)
        ->and($bundle['knowledge'][0]['provenance']['snapshot_id'])->toBe($newer->snapshot_id)
        ->and($bundle['knowledge'][0]['provenance']['sha256'])->toBe($newer->sha256)
        ->and($bundle['knowledge'][0]['provenance']['dataset_rows_sha256'])->toBe($source['rows_sha256'])
        ->and($bundle['knowledge'][0]['provenance']['feature_version'])->toBe(FeatureEngine::VERSION)
        ->and($bundle['knowledge'][0]['provenance']['labels'][0]['id'])->toBe($label->candle_label_id);
});

it('rejects malformed audit budgets without creating models', function (string $timeout) {
    $this->artisan('trademinator:human-candle-audit', ['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--timeout' => $timeout])->expectsOutputToContain('--timeout must be an integer')->assertFailed();
    $this->assertDatabaseCount('intelligence_models', 0);
})->with(['0', '-1', 'oops', '3601']);
