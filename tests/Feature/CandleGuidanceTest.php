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
        'intelligence.knn.train_size' => 36, 'intelligence.knn.test_size' => 12,
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

it('builds a separate three-action Candle Training comparison from matching authorized labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot();
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    foreach (array_slice($rows, 0, 12) as $index => $row) {
        candleGuidanceAction($manifest, $row, $trainer, $index % 2 === 0 ? 'buy' : 'sell');
    }

    $bundle = app(CandleGuidance::class)->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle'];
    expect($bundle['samples'])->toBe(12);
    expect($bundle['minimum_samples'])->toBe(8);
    expect($bundle['comparison'])->toHaveKeys(['baseline_without_candle', 'candle_human_only', 'combined']);
    expect($bundle['comparison']['candle_human_only']['production_eligible'])->toBeFalse();
    expect(strlen($bundle['label_provenance_sha256']))->toBe(64);
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
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(2);
    config(['operations.owner_uuid' => $users[0]->user_id, 'human_training.trainer_uuids' => []]);
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(1);
    config(['operations.owner_uuid' => null]);
    expect($service->compare($manifest, $rows, config('intelligence.knn'), microtime(true) + 30)['bundle']['samples'])->toBe(0);
});
