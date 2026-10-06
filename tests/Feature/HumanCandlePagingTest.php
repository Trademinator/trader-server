<?php

use App\Domain\Intelligence\HumanCandleKnn;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Research\DatasetStore;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

it('loads ordered snapshot identities once and hydrates bounded primary-key batches without losing annotations', function () {
    $this->travelTo('2024-01-01 06:00:00 UTC');
    $path = sys_get_temp_dir().'/human-candle-paging-'.Str::uuid7();
    $trainer = User::factory()->create();
    config(['research.path' => $path, 'operations.owner_uuid' => $trainer->user_id,
        'operations.owner_uuids' => [], 'human_training.trainer_uuids' => [],
        'human_training.enabled' => true, 'human_training.candle_enabled' => true]);

    try {
        $manifest = IntelligenceFixtures::snapshot(305);
        [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
        $annotate = function (array $source, array $row, string $action) use ($trainer): void {
            $payload = array_replace(HumanTrainingSnapshot::factory()->make()->payload, [
                'feature_version' => $source['feature_version'], 'decision_at_ms' => $row['decision_at_ms'],
                'microtimestamp' => $row['microtimestamp'], 'keys' => $source['keys'],
                'exchange' => $source['exchange'], 'symbol' => $source['symbol'], 'period' => $source['period'],
                'vector' => NormalizedVector::from($row['vector'], $source['keys']),
                'features' => array_combine($source['keys'], $row['vector']),
                'horizon_candles' => $source['label_definition']['horizon'],
                'feature_sha256' => $row['source']['feature_sha256'] ?? null,
            ]);
            $snapshot = HumanTrainingSnapshot::factory()->create([
                'dataset_id' => $source['dataset_id'], 'payload' => $payload,
                'market_key' => ModelStore::marketKey($source['exchange'], $source['symbol'], $source['period']),
                'snapshot_key' => hash('sha256', (string) Str::uuid7()),
            ]);
            HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
                'trainer_id' => $trainer->user_id, 'action' => $action, 'created_at' => now(), 'updated_at' => now()]);
        };
        // Insert in reverse chronological order so primary-key order is different.
        foreach (array_reverse($rows) as $row) {
            $annotate($manifest, $row, $row['label'] === 'hodl' ? 'hold' : $row['label']);
        }
        // Duplicates straddle the original 100-candle boundary in a new dataset.
        $revision = IntelligenceFixtures::snapshot(305);
        $annotate($revision, $rows[99], 'sell');
        $annotate($revision, $rows[100], 'sell');

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => count($event->bindings)];
        });
        $audit = app(HumanCandleKnn::class)->audit($manifest, microtime(true) + 30);
        expect($audit['samples'])->toBe(305)
            ->and($audit['class_counts'])->toBe(['buy' => 101, 'hold' => 101, 'sell' => 103])
            ->and($audit['annotation_diagnostics']['duplicate_eligible_snapshots'])->toBe(2)
            ->and($audit['annotation_diagnostics']['performance']['snapshot_batches'])->toBe(4)
            ->and($audit['annotation_diagnostics']['excluded'])->toBe([])
            ->and($audit['validation_performed'])->toBeFalse();

        $payloadReads = array_values(array_filter($queries,
            fn (array $query): bool => (bool) preg_match('/^select \* from ["`]?human_training_snapshots["`]?\s/i', $query['sql'])));
        expect($payloadReads)->toHaveCount(4);
        foreach ($payloadReads as $query) {
            expect(strtolower($query['sql']))->not->toContain('order by')->not->toContain('offset')
                ->and($query['bindings'])->toBeLessThanOrEqual(100);
        }
        $identityReads = array_values(array_filter($queries,
            fn (array $query): bool => (bool) preg_match('/^select ["`]?snapshot_id["`]? from ["`]?human_training_snapshots["`]?\s/i', $query['sql'])));
        expect($identityReads)->toHaveCount(1);
        $this->assertDatabaseCount('human_candle_labels', 307);
        $this->assertDatabaseCount('intelligence_models', 0);
    } finally {
        File::deleteDirectory($path);
    }
});
