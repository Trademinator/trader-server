<?php

use App\Domain\Intelligence\HumanGuidance;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Research\DatasetStore;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $path = sys_get_temp_dir().'/human-outcome-'.Str::uuid7();
    config([
        'research.path' => $path.'/research',
        'intelligence.path' => $path.'/models',
        'human_training.enabled' => true,
        'human_training.trend_enabled' => true,
        'human_training.min_samples' => 5,
        'human_training.k' => 3,
    ]);
    $this->travelTo('2024-01-01 04:10:00 UTC');
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function humanOutcomeOpinion(array $manifest, array $row, User $trainer, ?string $label = null): HumanTrainingSnapshot
{
    $payload = array_replace(HumanTrainingSnapshot::factory()->make()->payload, [
        'version' => HumanTraining::VERSION,
        'feature_version' => $manifest['feature_version'],
        'decision_at_ms' => $row['decision_at_ms'],
        'microtimestamp' => $row['microtimestamp'],
        'keys' => $manifest['keys'],
        'exchange' => $manifest['exchange'],
        'symbol' => $manifest['symbol'],
        'period' => $manifest['period'],
        'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
        'features' => array_combine($manifest['keys'], $row['vector']),
        'horizon_candles' => $manifest['label_definition']['horizon'],
        'feature_sha256' => $row['source']['feature_sha256'] ?? null,
    ]);
    $snapshot = HumanTrainingSnapshot::factory()->create([
        'dataset_id' => $manifest['dataset_id'],
        'payload' => $payload,
        'market_key' => ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']),
        'snapshot_key' => hash('sha256', (string) Str::uuid7()),
    ]);
    HumanTrainingReview::factory()->create([
        'snapshot_id' => $snapshot->snapshot_id,
        'trainer_id' => $trainer->user_id,
        'label' => $label ?? $row['label'],
        'confidence' => 100,
        'submitted_at' => now(),
    ]);

    return $snapshot;
}

it('trains independent Human Outcome knowledge from authorized five-class reviews', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(30);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    foreach ($rows as $row) {
        humanOutcomeOpinion($manifest, $row, $trainer);
    }

    $bundle = app(HumanGuidance::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];

    expect($bundle['status'])->toBe('validated')
        ->and($bundle['influence'])->toBeTrue()
        ->and($bundle['samples'])->toBe(30)
        ->and($bundle['knowledge_rows'])->toBe(30)
        ->and($bundle['class_counts']['bull'])->toBe(10)
        ->and($bundle['class_counts']['neutral'])->toBe(10)
        ->and($bundle['class_counts']['bear'])->toBe(10);
});

it('normalizes legacy hold reviews to neutral Outcome training', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(6);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    foreach ($rows as $i => $row) {
        humanOutcomeOpinion($manifest, $row, $trainer, $i === 1 ? 'hold' : $row['label']);
    }

    $bundle = app(HumanGuidance::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];

    expect($bundle['status'])->toBe('validated')
        ->and($bundle['class_counts']['neutral'])->toBe(2)
        ->and($bundle['class_counts'])->not->toHaveKey('hold');
});

it('excludes unauthorized Human Outcome snapshots', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $manifest = IntelligenceFixtures::snapshot(6);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);

    humanOutcomeOpinion($manifest, $rows[0], $owner);
    humanOutcomeOpinion($manifest, $rows[1], $other);
    $bundle = app(HumanGuidance::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];

    expect($bundle['samples'])->toBe(1)
        ->and($bundle['status'])->toBe('insufficient_outcome_training')
        ->and($bundle['influence'])->toBeFalse();
});

it('disables Human Outcome influence when Trend Training is disabled', function () {
    config(['human_training.trend_enabled' => false]);
    $manifest = IntelligenceFixtures::snapshot(6);

    $bundle = app(HumanGuidance::class)->train($manifest, config('intelligence.knn'), microtime(true) + 30)['bundle'];

    expect($bundle['status'])->toBe('outcome_training_disabled')
        ->and($bundle['samples'])->toBe(0)
        ->and($bundle['influence'])->toBeFalse();
});
