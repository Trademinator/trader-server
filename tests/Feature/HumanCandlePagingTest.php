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

it('keyset-pages ordered snapshot identities and hydrates narrow bounded batches without losing annotations', function () {
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
        foreach (array_reverse($rows) as $row) {
            $annotate($manifest, $row, $row['action_label'] === 'hodl' ? 'hold' : $row['action_label']);
        }
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
            ->and($audit['annotation_diagnostics']['performance']['snapshot_batches'])->toBe(13)
            ->and($audit['annotation_diagnostics']['excluded'])->toBe([])
            ->and($audit['validation_performed'])->toBeFalse();

        $payloadReads = array_values(array_filter($queries, fn (array $query): bool =>
            str_contains(strtolower($query['sql']), 'human_training_snapshots')
            && str_contains(strtolower($query['sql']), 'payload')
            && str_contains(strtolower($query['sql']), 'snapshot_id')
            && str_contains(strtolower($query['sql']), ' in ')));
        expect($payloadReads)->toHaveCount(13);
        foreach ($payloadReads as $query) {
            expect(strtolower($query['sql']))->not->toContain('select *')->not->toContain('order by')->not->toContain('offset')
                ->and($query['bindings'])->toBeLessThanOrEqual(25);
        }

        $identityReads = array_values(array_filter($queries, fn (array $query): bool =>
            str_contains(strtolower($query['sql']), 'human_training_snapshots')
            && str_contains(strtolower($query['sql']), 'dataset_id')
            && str_contains(strtolower($query['sql']), 'order by')
            && ! str_contains(strtolower($query['sql']), 'payload')));
        expect(count($identityReads))->toBeGreaterThanOrEqual(13);
        foreach ($identityReads as $query) {
            expect(strtolower($query['sql']))->not->toContain('offset');
        }

        $this->assertDatabaseCount('human_candle_labels', 307);
        $this->assertDatabaseCount('intelligence_models', 0);
    } finally {
        File::deleteDirectory($path);
    }
});
