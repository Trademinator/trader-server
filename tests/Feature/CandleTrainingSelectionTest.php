<?php

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\TrainingCandidateSelector;
use App\Domain\Research\DatasetStore;
use App\Models\Exchange;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\Market;
use App\Models\MarketSignal;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    config([
        'research.path' => sys_get_temp_dir().'/candle-selection-'.Str::uuid7(),
        'human_training.enabled' => true,
        'human_training.trend_enabled' => true,
        'human_training.candle_enabled' => true,
        'human_training.candidate_attempts' => 24,
        'human_training.trainer_uuids' => [],
        'operations.owner_uuid' => null,
        'operations.owner_uuids' => [],
    ]);
    Http::preventStrayRequests();
});

afterEach(function () {
    DB::disableQueryLog();
    DB::flushQueryLog();
    File::deleteDirectory(config('research.path'));
});

/** Real immutable dataset with source candles; no live exchange access is needed. */
function candleSelectionDataset(int $count = 60): array
{
    $manifest = IntelligenceFixtures::snapshot($count);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    IntelligenceFixtures::feature(-1, 0.5);
    foreach ($rows as $index => &$row) {
        IntelligenceFixtures::feature($index, ($index % 3) / 2);
        $row['candle'] = ['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'];
    }
    unset($row);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    return [$manifest, $rows];
}

function candleSelectionOwner(): User
{
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->user_id]);

    return $user;
}

/** Large unrelated chart payloads must not be decoded just to choose an unseen candle. */
function candleSelectionRecordedMetadata(array $manifest, array $rows, User $trainer, string $table): void
{
    $snapshots = $opinions = [];
    foreach ($rows as $row) {
        $id = (string) Str::uuid7();
        $snapshots[] = [
            'snapshot_id' => $id, 'snapshot_key' => hash('sha256', $id),
            'market_key' => ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']),
            'dataset_id' => $manifest['dataset_id'], 'decision_at_ms' => $row['decision_at_ms'],
            'version' => HumanTraining::VERSION, 'sha256' => str_repeat('0', 64),
            // Deliberately invalid checksum: selecting a different candle must not hydrate it.
            'payload' => json_encode(['unused_chart' => str_repeat('x', 8192)], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ];
        if ($table === 'human_candle_labels') {
            $opinions[] = ['candle_label_id' => (string) Str::uuid7(), 'snapshot_id' => $id,
                'trainer_id' => $trainer->user_id, 'action' => 'hold', 'created_at' => now(), 'updated_at' => now()];
        } else {
            $opinions[] = ['review_id' => (string) Str::uuid7(), 'snapshot_id' => $id,
                'trainer_id' => $trainer->user_id, 'label' => 'hold',
                'shown_at' => now()->subMinute(), 'expires_at' => now()->addHour(), 'submitted_at' => now()];
        }
    }
    foreach (array_chunk($snapshots, 100) as $batch) {
        DB::table('human_training_snapshots')->insert($batch);
    }
    foreach (array_chunk($opinions, 100) as $batch) {
        DB::table($table)->insert($batch);
    }
}

it('starts Candle Training without scanning labelled chart payloads or building the redirect chart', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(600);
    candleSelectionRecordedMetadata($manifest, array_slice($rows, 0, -1), $trainer, 'human_candle_labels');
    $decision = $rows[array_key_last($rows)]['decision_at_ms'];
    DB::enableQueryLog();

    $this->actingAs($trainer)->post(route('human-training.candles.start'), ['dataset' => $manifest['dataset_id']])
        ->assertRedirect(route('human-training.candles.show', ['dataset' => $manifest['dataset_id'], 'decision_at_ms' => $decision]));

    $queries = collect(DB::getQueryLog())->pluck('query');
    $snapshotSelects = $queries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
        && str_contains($sql, 'human_training_snapshots'));
    expect($snapshotSelects->count())->toBeLessThanOrEqual(6);
    expect($queries->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'row_number()')))->toBeFalse();
    expect($snapshotSelects->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'offset')))->toBeFalse();
    $this->assertDatabaseCount('human_candle_labels', 599);
    Http::assertNothingSent();
});

it('uses the same bounded selection for Trend Training without rescanning all reviews', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(600);
    candleSelectionRecordedMetadata($manifest, array_slice($rows, 0, -1), $trainer, 'human_training_reviews');

    $review = app(HumanTraining::class)->assign($trainer, $manifest['dataset_id']);

    expect($review->snapshot->decision_at_ms)->toBe($rows[array_key_last($rows)]['decision_at_ms']);
    $this->assertDatabaseCount('human_training_reviews', 600);
    expect($review->submitted_at)->toBeNull();
});

it('starts both human training modes with more than 3000 eligible examples', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(3105);

    $this->actingAs($trainer)->post(route('human-training.candles.start'), ['dataset' => $manifest['dataset_id']])
        ->assertRedirect();
    $review = app(HumanTraining::class)->assign($trainer, $manifest['dataset_id']);

    expect($review->snapshot->decision_at_ms)->toBeIn(array_column($rows, 'decision_at_ms'));
    expect($review->submitted_at)->toBeNull();
    $this->assertDatabaseCount('human_training_reviews', 1);
    $this->assertDatabaseCount('human_candle_labels', 0);
});

it('keeps all-labelled Candle Training usable with a bounded verified fallback', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(60);
    $snapshots = app(HumanTraining::class)->snapshotsForRows($manifest, $rows);
    foreach ($snapshots as $snapshot) {
        HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
            'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    }
    $hydrated = 0;
    HumanTrainingSnapshot::retrieved(function () use (&$hydrated): void {
        $hydrated++;
    });

    $decision = app(CandleTraining::class)->start($trainer, $manifest['dataset_id']);

    expect($decision)->toBeIn(array_column($rows, 'decision_at_ms'));
    expect($hydrated)->toBeGreaterThan(0)->toBeLessThanOrEqual(TrainingCandidateSelector::MAX_CHECKS);
    $this->assertDatabaseCount('human_candle_labels', 60);
    $this->assertDatabaseCount('human_training_snapshots', 60);
});

it('treats a repaired chart as unlabelled without transferring the old opinion', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(1);
    $service = app(HumanTraining::class);
    $old = $service->snapshotForRow($manifest, $rows[0]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $old->snapshot_id,
        'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    // Only earlier chart context changes; the selected candle/vector is identical.
    DB::table('tickers')->where('exchange', 'kraken')->where('symbol', 'BTC/USD')->where('period', '1m')
        ->where('microtimestamp', IntelligenceFixtures::START - 60000)
        ->update(['payload' => json_encode(['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10.5', 'volume' => '1'])]);
    Cache::flush();

    $candidate = $service->candidateSnapshot($trainer, $manifest, $rows, 'candleLabels');

    expect($candidate['snapshot']->snapshot_id)->not->toBe($old->snapshot_id);
    expect($candidate['snapshot']->payload['revision']['requires_review'])->toBeTrue();
    expect($candidate['snapshot']->candleLabels()->count())->toBe(0);
    $this->assertDatabaseCount('human_candle_labels', 1);
});

it('does not borrow another trainers labels when prioritizing candidates', function () {
    $trainer = candleSelectionOwner();
    $other = User::factory()->create();
    [$manifest, $rows] = candleSelectionDataset(1);
    $service = app(HumanTraining::class);
    $snapshot = $service->snapshotForRow($manifest, $rows[0]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $other->user_id, 'action' => 'hold']);

    expect($service->candidateSnapshot($trainer, $manifest, $rows, 'candleLabels')['snapshot']->snapshot_id)->toBe($snapshot->snapshot_id);
    expect($snapshot->candleLabels()->where('trainer_id', $trainer->user_id)->exists())->toBeFalse();
});

it('still rejects missing source history rather than choosing a stored chart blindly', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(1);
    $snapshot = app(HumanTraining::class)->snapshotForRow($manifest, $rows[0]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    // Bypass read caches only after changing canonical storage, just as a repair must.
    DB::table('tickers')->delete();
    Cache::flush();

    expect(fn () => app(CandleTraining::class)->start($trainer, $manifest['dataset_id']))
        ->toThrow(ValidationException::class);
});

it('does not hide checksum failures on the selected snapshot', function () {
    $trainer = candleSelectionOwner();
    [$manifest, $rows] = candleSelectionDataset(1);
    $snapshot = app(HumanTraining::class)->snapshotForRow($manifest, $rows[0]);
    DB::table('human_training_snapshots')->where('snapshot_id', $snapshot->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);

    expect(fn () => app(CandleTraining::class)->start($trainer, $manifest['dataset_id']))->toThrow(LogicException::class, 'checksum');
});

it('limits historical signal hydration and preserves both causal cutoffs and UUID tie breaks', function () {
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $start = IntelligenceFixtures::START;
    $signals = [];
    for ($i = 0; $i < 1000; $i++) {
        $id = (string) Str::uuid7();
        $signals[] = ['market_signal_id' => $id, 'market_id' => $market->market_id, 'snapshot_key' => hash('sha256', $id),
            'period' => '1m', 'model_id' => null, 'decision_at_ms' => $start + $i * 60000,
            'recorded_at_ms' => $start + $i * 60000, 'is_change' => false, 'action' => 'hodl', 'reason' => 'older', 'payload' => '{}'];
    }
    $cutoff = $start + 1000 * 60000;
    foreach ([
        ['00000000-0000-7000-8000-0000000000a1', $cutoff, $cutoff, 'tie_loser', '1m'],
        ['00000000-0000-7000-8000-0000000000a2', $cutoff, $cutoff, 'tie_winner', '1m'],
        ['00000000-0000-7000-8000-0000000000b1', $cutoff + 1, $cutoff, 'future_decision', '1m'],
        ['00000000-0000-7000-8000-0000000000b2', $cutoff - 1, $cutoff + 1, 'future_recording', '1m'],
        ['00000000-0000-7000-8000-0000000000b3', null, $cutoff, 'unknown_decision', '1m'],
        ['00000000-0000-7000-8000-0000000000b4', $cutoff, $cutoff, 'wrong_period', '1h'],
    ] as [$id, $decision, $recorded, $reason, $period]) {
        $signals[] = ['market_signal_id' => $id, 'market_id' => $market->market_id, 'snapshot_key' => hash('sha256', $id),
            'period' => $period, 'model_id' => null, 'decision_at_ms' => $decision, 'recorded_at_ms' => $recorded,
            'is_change' => false, 'action' => 'hodl', 'reason' => $reason, 'payload' => '{}'];
    }
    foreach (array_chunk($signals, 50) as $batch) {
        DB::table('market_signals')->insert($batch);
    }
    $hydrated = 0;
    MarketSignal::retrieved(function () use (&$hydrated): void {
        $hydrated++;
    });
    DB::enableQueryLog();

    $method = new ReflectionMethod(HumanTraining::class, 'batchModelObservations');
    $result = $method->invoke(app(HumanTraining::class), ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m'],
        [$start - 1, $start, $cutoff, $cutoff]);

    expect($result[$start - 1])->toBeNull();
    expect($result[$start]['reason'])->toBe('older');
    expect($result[$cutoff]['reason'])->toBe('tie_winner');
    expect($hydrated)->toBe(2);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'market_signals'));
    expect($queries)->toHaveCount(3);
    foreach ($queries as $sql) {
        expect(strtolower($sql))->toContain('limit 1');
    }
});
