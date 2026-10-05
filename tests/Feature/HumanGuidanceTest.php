<?php

use App\Domain\Intelligence\HumanGuidance;
use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Research\DatasetStore;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/human-guidance-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'human_training.min_samples' => 20,
        'human_training.enabled' => true, 'human_training.trend_enabled' => true]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function humanGuidanceOpinion(array $manifest, array $row, User $trainer, ?string $label = null): HumanTrainingSnapshot
{
    $payload = HumanTrainingSnapshot::factory()->make()->payload;
    $payload = array_replace($payload, ['feature_version' => $manifest['feature_version'],
        'decision_at_ms' => $row['decision_at_ms'], 'microtimestamp' => $row['microtimestamp'],
        'keys' => $manifest['keys'], 'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
        'features' => array_combine($manifest['keys'], $row['vector']),
        'horizon_candles' => $manifest['label_definition']['horizon'], 'feature_sha256' => $row['source']['feature_sha256'] ?? null]);
    $snapshot = HumanTrainingSnapshot::factory()->create(['dataset_id' => $manifest['dataset_id'], 'payload' => $payload]);
    HumanTrainingReview::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id,
        'label' => $label ?? ['buy' => 'super_bull', 'hodl' => 'hold', 'sell' => 'super_bear'][$row['label']],
        'confidence' => 100, 'submitted_at' => now()]);

    return $snapshot;
}

it('excludes trend reviews from production scoring even when the legacy trend flag is enabled', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => strtoupper($trainer->user_id)]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $baseline = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    foreach (array_slice($rows, 0, 100) as $row) {
        humanGuidanceOpinion($manifest, $row, $trainer);
    }
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    $human = $report['human_guidance'];
    expect($human['samples'])->toBe(0);
    expect($human['reason'])->toBe('excluded_from_scoring');
    expect($human['influence'])->toBeFalse();
    expect($human)->not->toHaveKey('estimator');
    expect($report['selection'])->toBe($baseline['selection']);
    expect($report['holdout'])->toBe($baseline['holdout']);
    expect($report['human_keys'])->toBe([]);
    expect(app(DatasetStore::class)->manifest($manifest['dataset_id'])['rows_sha256'])->toBe($manifest['rows_sha256']);
});

it('keeps later human reviews and final holdout targets out of auxiliary fitting and model selection', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach (array_slice($rows, 0, 94) as $row) {
        humanGuidanceOpinion($manifest, $row, $trainer);
    }
    $service = app(HumanGuidance::class);
    $first = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    humanGuidanceOpinion($manifest, $rows[220], $trainer, 'bear');
    for ($i = 220; $i < count($rows); $i++) {
        $rows[$i]['label'] = 'hodl';
    }
    $second = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($second['label_provenance_sha256'])->toBe($first['label_provenance_sha256']);
    expect($second['samples'])->toBe(94);
    foreach (['machine_only', 'combined'] as $name) {
        expect($second['comparison'][$name]['selection'])->toBe($first['comparison'][$name]['selection']);
    }
    expect($second['comparison']['human_only']['holdout'])->not->toBe($first['comparison']['human_only']['holdout']);
});

it('excludes disputed snapshots, incompatible source vectors and revoked trainers', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id]]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $first = humanGuidanceOpinion($manifest, $rows[0], $users[0]);
    HumanTrainingReview::factory()->create(['snapshot_id' => $first->snapshot_id, 'trainer_id' => $users[1]->user_id, 'label' => 'bear', 'submitted_at' => now()]);
    humanGuidanceOpinion($manifest, $rows[1], $users[0]);
    $mismatch = $rows[2];
    $mismatch['vector'] = [0.123];
    humanGuidanceOpinion($manifest, $mismatch, $users[0]);
    $service = app(HumanGuidance::class);
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(1);
    config(['operations.owner_uuid' => null, 'human_training.trainer_uuids' => []]);
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(0);
});

it('rejects promotion without meaningful improvement or with reduced coverage or more contradictions', function (array $candidate, bool $eligible) {
    $baseline = ['semantic_precision' => 0.6, 'coverage' => 0.2, 'contradiction_rate' => 0.02];
    $combined = array_replace(['eligible' => true, 'semantic_precision' => 0.7, 'coverage' => 0.2, 'contradiction_rate' => 0.01], $candidate);
    expect(app(HumanGuidance::class)->improves($baseline, $combined))->toBe($eligible);
})->with([[[], true], [['eligible' => false], false], [['semantic_precision' => 0.61], false],
    [['coverage' => 0.19], false], [['contradiction_rate' => 0.03], false]]);

it('keeps even a validated legacy trend classifier out of published knowledge and inference', function () {
    $this->travelTo('2024-01-01 09:00:00 UTC');
    config(['human_training.k' => 1, 'intelligence.knn.min_train_size' => 18,
        'intelligence.knn.min_effective_neighbors' => 1.0, 'intelligence.knn.max_distance' => 1.0]);
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(500);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $manifest['keys'] = ['candle.body', 'candle.upper_wick'];
    foreach ($rows as $i => &$row) {
        $class = $i < 200 ? $i % 2 : (int) (($i * 17) % 23 >= 12);
        $y = $i < 200 ? intdiv($i, 2) / 100 : (($i * 37) % 99) / 100;
        $row['vector'] = [0.499 + $class * 0.002, $y];
        $row['label'] = $class ? 'sell' : 'buy';
        $row['semantic'] = ['bottom' => ! $class, 'top' => (bool) $class];
    }
    unset($row);
    foreach (array_slice($rows, 0, 198) as $row) {
        humanGuidanceOpinion($manifest, $row, $trainer);
    }
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);
    $legacy = app(HumanGuidance::class)->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($legacy['status'])->toBe('validated');
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    expect($report['human_guidance']['reason'])->toBe('excluded_from_scoring');
    expect($report['human_keys'])->toBe([]);
    $artifact = app(ModelStore::class)->load($report['model_id']);
    expect($artifact['human_guidance'])->not->toHaveKey('estimator');
    expect($artifact['available_at_ms'])->toBeLessThan(now()->getTimestampMs());
    expect($artifact['knowledge'])->toHaveCount(500);
    foreach ($artifact['knowledge'] as $row) {
        expect($row['vector'])->toHaveCount(2);
    }
    $this->travelTo('2024-01-01 09:02:00 UTC');
    IntelligenceFixtures::feature(540, 0.499);
    IntelligenceFixtures::feature(541, 0.499);
    foreach ([540, 541] as $minute) {
        $feature = DB::table('market_features')->where('microtimestamp', IntelligenceFixtures::START + $minute * 60000)->first();
        $payload = json_decode($feature->payload, true);
        $payload['features']['candle.upper_wick'] = 0.5;
        DB::table('market_features')->where('feature_id', $feature->feature_id)->update(['payload' => json_encode($payload)]);
    }
    $prediction = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($prediction['scoring']['human_trend'])->toBe(['status' => 'excluded', 'weight' => 0.0]);
    config(['human_training.enabled' => false]);
    $disabled = app(MarketIntelligence::class)->predict('kraken', 'BTC/USD', '1m');
    expect($disabled['action'])->toBe($prediction['action']);
    expect($disabled['reason'])->toBe($prediction['reason']);

    config(['human_training.enabled' => true]);
    for ($i = 440; $i < count($rows); $i++) {
        $rows[$i]['label'] = 'hodl';
    }
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])->update(['manifest' => json_encode($manifest)]);
    $rejected = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    expect($rejected['human_guidance']['reason'])->toBe('excluded_from_scoring');
    expect($rejected['human_keys'])->toBe([]);
    expect($rejected['selection'])->toBe($report['selection']);
});
