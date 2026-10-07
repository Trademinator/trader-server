<?php

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Research\DatasetStore;
use App\Models\Exchange;
use App\Models\HumanCandleLabel;
use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    config([
        'research.path' => sys_get_temp_dir().'/candle-auto-label-'.Str::uuid7(),
        'human_training.enabled' => true,
        'human_training.candle_enabled' => true,
        'human_training.chart_candles' => 90,
        'human_training.trainer_uuids' => [],
        'operations.owner_uuid' => null,
        'operations.owner_uuids' => [],
    ]);
});

afterEach(function () {
    DB::disableQueryLog();
    DB::flushQueryLog();
    File::deleteDirectory(config('research.path'));
});

/** Real source-bearing rows and snapshots, with only the exchange network boundary mocked. */
function autoLabelDataset(int $count, ?array $candle = null): array
{
    $manifest = IntelligenceFixtures::snapshot($count);
    [, $rows] = app(DatasetStore::class)->load($manifest['dataset_id']);
    IntelligenceFixtures::feature(-1, 0.5);
    foreach ($rows as $index => &$row) {
        IntelligenceFixtures::feature($index, ($index % 3) / 2);
        $row['candle'] = $candle ?? ['open' => '10', 'high' => '10', 'low' => '10', 'close' => '10', 'volume' => '1'];
        DB::table('tickers')->where('microtimestamp', $row['microtimestamp'])
            ->update(['payload' => json_encode($row['candle'], JSON_THROW_ON_ERROR)]);
        $feature = DB::table('market_features')->where('microtimestamp', $row['microtimestamp'])->value('payload');
        $row['source'] = ['feature_sha256' => hash('sha256', $feature)];
    }
    unset($row);
    $bytes = implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows));
    $manifest['rows_sha256'] = hash('sha256', $bytes);
    $path = app(DatasetStore::class)->directory($manifest['dataset_id']);
    file_put_contents($path.'/rows.jsonl', $bytes);
    file_put_contents($path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    DB::table('research_datasets')->where('dataset_id', $manifest['dataset_id'])
        ->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);

    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    return [$manifest, $rows];
}

it('preserves only this trainers compatible labels with bounded reads and no snapshot writes', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    Http::preventStrayRequests();
    $trainer = User::factory()->create();
    $other = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(121);
    $snapshots = app(HumanTraining::class)->snapshotsForRows($manifest, $rows);
    foreach ($rows as $index => $row) {
        if (! in_array($index, [1, 119], true)) {
            HumanCandleLabel::factory()->create(['snapshot_id' => $snapshots[$row['decision_at_ms']]->snapshot_id,
                'trainer_id' => $trainer->user_id, 'action' => 'hold']);
        }
    }
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshots[$rows[1]['decision_at_ms']]->snapshot_id,
        'trainer_id' => $other->user_id, 'action' => 'hold']);
    DB::enableQueryLog();

    $labels = [];
    foreach ([0, 50, 100] as $offset) {
        $response = $this->actingAs($trainer)
            ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), ['offset' => $offset])
            ->assertOk()->assertJsonPath('total', 121)
            ->assertJsonPath('processed', min($offset + 50, 121))
            ->assertJsonPath('next_offset', $offset === 100 ? null : $offset + 50);
        if ($offset === 50) {
            $response->assertJsonPath('labels', [])->assertJsonPath('count', 0);
        }
        $labels = [...$labels, ...$response->json('labels')];
    }

    expect($labels)->toBe(array_map(fn (int $index): array => [
        'time' => intdiv($rows[$index]['microtimestamp'], 1000),
        'decision_at_ms' => $rows[$index]['decision_at_ms'], 'action' => 'hold',
    ], [1, 119]));

    $queries = collect(DB::getQueryLog())->pluck('query');
    $snapshotQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'human_training_snapshots'));
    expect($snapshotQueries->count())->toBeLessThanOrEqual(6);
    expect($snapshotQueries->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'offset')))->toBeFalse();
    expect($queries->contains(fn (string $sql): bool => str_contains($sql, 'market_signals')))->toBeFalse();
    expect($snapshotQueries->every(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')))->toBeTrue();
    $this->assertDatabaseCount('human_candle_labels', 120);
    $this->assertDatabaseCount('human_training_snapshots', 121);
    Http::assertNothingSent();
});

it('includes saved opinions when delete-all has been staged without changing stored labels', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(3);
    $snapshot = app(HumanTraining::class)->snapshotForRow($manifest, $rows[1]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
        'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    DB::enableQueryLog();

    $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), ['include_existing' => true])
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('labels.0.action', 'hold');

    expect(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'human_training_snapshots')))->toBeFalse();
    $this->assertDatabaseCount('human_candle_labels', 1);
    $this->assertDatabaseCount('human_training_snapshots', 1);
});

it('allows a fresh suggestion after source changes without publishing a replacement snapshot', function (string $change) {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(3);
    $snapshot = app(HumanTraining::class)->snapshotForRow($manifest, $rows[1]);
    $original = $snapshot->verifiedPayload();
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
        'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    if ($change === 'context') {
        DB::table('tickers')->where('microtimestamp', $rows[0]['microtimestamp'])
            ->update(['payload' => json_encode(['open' => '10', 'high' => '11', 'low' => '9', 'close' => '10.5', 'volume' => '1'])]);
    } elseif ($change === 'feature') {
        DB::table('market_features')->where('microtimestamp', $rows[1]['microtimestamp'])->update(['payload' => '{}']);
    } else {
        DB::table('tickers')->where('microtimestamp', $rows[1]['microtimestamp'])->delete();
    }
    Cache::flush();

    $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']))
        ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('labels.0.decision_at_ms', $rows[1]['decision_at_ms']);

    $this->assertDatabaseCount('human_candle_labels', 1);
    $this->assertDatabaseCount('human_training_snapshots', 1);
    expect($snapshot->fresh()->verifiedPayload())->toBe($original);
})->with(['repaired chart context' => 'context', 'changed feature provenance' => 'feature', 'missing candle' => 'missing']);

it('rejects corrupt saved chart checksums rather than trusting their labels', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(3);
    $snapshot = app(HumanTraining::class)->snapshotForRow($manifest, $rows[1]);
    HumanCandleLabel::factory()->create(['snapshot_id' => $snapshot->snapshot_id,
        'trainer_id' => $trainer->user_id, 'action' => 'hold']);
    DB::table('human_training_snapshots')->where('snapshot_id', $snapshot->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);

    expect(fn () => app(CandleTraining::class)->autoLabels($trainer, $manifest['dataset_id']))
        ->toThrow(LogicException::class, 'Human training snapshot checksum mismatch.');

    $this->assertDatabaseCount('human_candle_labels', 1);
    $this->assertDatabaseCount('human_training_snapshots', 1);
});

it('preserves candle-chain context across auto-label page boundaries', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(55,
        ['open' => '10', 'high' => '11', 'low' => '10', 'close' => '11', 'volume' => '1']);

    $this->actingAs($trainer)
        ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), ['offset' => 50])
        ->assertOk()->assertExactJson(['count' => 3, 'processed' => 55, 'total' => 55, 'next_offset' => null,
            'labels' => array_map(fn (int $index): array => [
                'time' => intdiv($rows[$index]['microtimestamp'], 1000),
                'decision_at_ms' => $rows[$index]['decision_at_ms'], 'action' => 'hold',
            ], [50, 51, 52])]);

    $this->assertDatabaseEmpty('human_candle_labels');
    $this->assertDatabaseEmpty('human_training_snapshots');
});

it('allows enough sequential auto-label requests to finish a larger dataset', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest] = autoLabelDataset(3);

    for ($request = 0; $request < 13; $request++) {
        $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']))
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('next_offset', null);
    }

    $this->assertDatabaseEmpty('human_candle_labels');
});

it('returns 422 for an auto-label offset outside the dataset without creating snapshots', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    $manifest = IntelligenceFixtures::snapshot(1);

    $this->actingAs($trainer)
        ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), ['offset' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['offset'])
        ->assertJsonPath('errors.offset.0', 'The auto-label position is outside this frozen dataset.');

    $this->assertDatabaseEmpty('human_training_snapshots');
});

it('returns 422 for a negative auto-label offset', function () {
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);

    $this->actingAs($trainer)->postJson(route('human-training.candles.auto-label', Str::uuid7()), ['offset' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors(['offset'])
        ->assertJsonPath('errors.offset.0', 'The offset field must be at least 0.');
});

it('returns 401 for unauthenticated auto-label requests', function () {
    $this->postJson(route('human-training.candles.auto-label', Str::uuid7()))->assertUnauthorized();
});

it('returns 403 for trainers without permission to auto-label', function () {
    $this->actingAs(User::factory()->create())->postJson(route('human-training.candles.auto-label', Str::uuid7()))
        ->assertForbidden();
});


it('leaves datasets with no interior candle unlabelled even when existing opinions are included', function (int $count, bool $includeExisting) {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest] = autoLabelDataset($count);

    $this->actingAs($trainer)
        ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), ['include_existing' => $includeExisting])
        ->assertOk()->assertExactJson(['labels' => [], 'count' => 0, 'processed' => $count,
            'total' => $count, 'next_offset' => null]);

    $this->assertDatabaseEmpty('human_candle_labels');
    $this->assertDatabaseEmpty('human_training_snapshots');
})->with([
    'one candle' => [1, false],
    'two candles' => [2, false],
    'one candle including existing' => [1, true],
    'two candles including existing' => [2, true],
]);

it('excludes dataset endpoints but retains interior page edges from every auto-label batch', function (bool $includeExisting) {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    $trainer = User::factory()->create();
    config(['operations.owner_uuid' => $trainer->user_id]);
    [$manifest, $rows] = autoLabelDataset(103);
    $labels = [];

    foreach ([0 => 49, 50 => 50, 100 => 2] as $offset => $expectedCount) {
        $response = $this->actingAs($trainer)
            ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), [
                'offset' => $offset, 'include_existing' => $includeExisting,
            ])
            ->assertOk()->assertJsonPath('count', $expectedCount)
            ->assertJsonPath('processed', min($offset + 50, 103))
            ->assertJsonPath('next_offset', $offset === 100 ? null : $offset + 50);
        $labels = [...$labels, ...$response->json('labels')];
    }

    expect($labels)->toBe(array_map(fn (int $index): array => [
        'time' => intdiv($rows[$index]['microtimestamp'], 1000),
        'decision_at_ms' => $rows[$index]['decision_at_ms'], 'action' => 'hold',
    ], range(1, 101)));

    // A resumed request may begin on any interior row, not only a multiple of 50.
    $this->actingAs($trainer)
        ->postJson(route('human-training.candles.auto-label', $manifest['dataset_id']), [
            'offset' => 49, 'include_existing' => $includeExisting,
        ])
        ->assertOk()->assertJsonPath('count', 50)
        ->assertJsonPath('labels.0.decision_at_ms', $rows[49]['decision_at_ms'])
        ->assertJsonPath('labels.49.decision_at_ms', $rows[98]['decision_at_ms']);

    $this->assertDatabaseEmpty('human_candle_labels');
    $this->assertDatabaseEmpty('human_training_snapshots');
})->with(['preserve saved opinions' => [false], 'include saved opinions' => [true]]);
