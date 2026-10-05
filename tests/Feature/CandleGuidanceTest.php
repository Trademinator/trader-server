<?php

use App\Domain\Intelligence\CandleGuidance;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Research\DatasetStore;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot as SnapshotModel;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/candle-guidance-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'intelligence.knn.min_train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'human_training.candle_min_samples' => 8,
        'human_training.candle_k' => 3, 'human_training.enabled' => true]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function candleGuidanceAction(array $manifest, array $row, User $trainer, string $action, bool $mismatchedSource = false): SnapshotModel
{
    $payload = SnapshotModel::factory()->make()->payload;
    $payload = array_replace($payload, ['feature_version' => $manifest['feature_version'],
        'decision_at_ms' => $row['decision_at_ms'], 'microtimestamp' => $row['microtimestamp'],
        'keys' => $manifest['keys'], 'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
        'features' => array_combine($manifest['keys'], $row['vector']),
        'horizon_candles' => $manifest['label_definition']['horizon'],
        'feature_sha256' => $mismatchedSource ? 'mismatch' : ($row['source']['feature_sha256'] ?? null)]);
    $snapshot = SnapshotModel::factory()->create(['dataset_id' => $manifest['dataset_id'], 'payload' => $payload]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id,
        'action' => $action, 'created_at' => now(), 'updated_at' => now()]);

    return $snapshot;
}

it('builds a separate HOLD-preserving three-action Candle Training comparison from matching authorized labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $actions = ['buy', 'hold', 'sell'];
    foreach (array_slice($rows, 0, 12) as $index => $row) {
        candleGuidanceAction($manifest, $row, $trainer, $actions[$index % 3]);
    }

    $bundle = app(CandleGuidance::class)->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['version'])->toBe('m4.4-candle-guidance-v3');
    expect($bundle['samples'])->toBe(12);
    expect($bundle['training_samples'])->toBe(12);
    expect($bundle['class_counts'])->toBe(['buy' => 4, 'hold' => 4, 'sell' => 4]);
    expect($bundle['training_class_counts'])->toBe(['buy' => 4, 'hold' => 4, 'sell' => 4]);
    expect($bundle['minimum_samples'])->toBe(8);
    expect($bundle['comparison'])->toHaveKeys(['baseline_without_candle', 'candle_human_only', 'combined']);
    expect($bundle['comparison']['candle_human_only']['production_eligible'])->toBeFalse();
    expect(strlen($bundle['label_provenance_sha256']))->toBe(64);
});

it('retains every eligible example in an imbalanced action set', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id, 'human_training.candle_min_samples' => 50]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    $actions = ['buy', 'buy', 'buy', 'buy', 'buy', 'buy', 'hold', 'hold', 'sell', 'sell'];
    foreach ($actions as $index => $action) {
        candleGuidanceAction($manifest, $rows[$index], $trainer, $action);
    }

    $bundle = app(CandleGuidance::class)->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(10);
    expect($bundle['training_samples'])->toBe(10);
    expect($bundle['class_counts'])->toBe(['buy' => 6, 'hold' => 2, 'sell' => 2]);
    expect($bundle['training_class_counts'])->toBe(['buy' => 6, 'hold' => 2, 'sell' => 2]);
    expect($bundle['status'])->toBe('insufficient_candle_labels');
});

it('excludes candle labels from revoked trainers and incompatible snapshots', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $users = User::factory()->count(2)->create();
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => [$users[1]->user_id],
        'human_training.candle_min_samples' => 50]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    candleGuidanceAction($manifest, $rows[0], $users[0], 'buy');
    candleGuidanceAction($manifest, $rows[1], $users[0], 'sell', true);
    candleGuidanceAction($manifest, $rows[2], $users[1], 'hold');

    $service = app(CandleGuidance::class);
    $bundle = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(2)->and($bundle['training_samples'])->toBe(2);
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => []]);
    $bundle = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(1)->and($bundle['training_samples'])->toBe(1);
    config(['operations.owner_uuid' => null]);
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(0);
});

it('retains abundant HOLDs and keeps policy selection independent of final holdout labels', function () {
    $this->travelTo('2024-01-01 09:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id, 'human_training.candle_min_samples' => 50]);
    $manifest = IntelligenceFixtures::snapshot(500);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach (array_slice($rows, 0, 150) as $index => $row) {
        candleGuidanceAction($manifest, $row, $trainer, $index < 5 ? 'buy' : ($index < 10 ? 'sell' : 'hold'));
    }
    $service = app(CandleGuidance::class);
    $first = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 60)['bundle'];
    expect($first['training_samples'])->toBe(150)
        ->and($first['training_class_counts'])->toBe(['buy' => 5, 'hold' => 140, 'sell' => 5])
        ->and($first['weight_candidates'])->toHaveKeys(['natural', 'target_priors'])
        ->and($first['weight_candidates']['target_priors']['class_weights']['buy'])->toBe(7.5);
    // First 40% fits the auxiliary. The last 20% of the remaining 60% is holdout.
    for ($i = 440; $i < count($rows); $i++) {
        $rows[$i]['label'] = 'hodl';
        $rows[$i]['semantic'] = ['bottom' => false, 'top' => false];
    }
    $second = $service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 60)['bundle'];
    expect($second['weight_candidates'])->toBe($first['weight_candidates'])
        ->and($second['training_samples'])->toBe(150)
        ->and($second['label_provenance_sha256'])->toBe($first['label_provenance_sha256']);
});
