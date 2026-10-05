<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\SnapshotRevisions;
use App\Domain\MarketData\HistoryChanges;
use App\Domain\MarketData\HistoryRecovery;
use App\Jobs\RecoverMarketHistory;
use App\Models\Exchange;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    Cache::flush();
    $path = sys_get_temp_dir().'/intelligence-recovery-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'history_backfill.enabled' => true, 'intelligence.enabled' => true, 'queue.default' => 'database',
        'history_backfill.queue' => 'history', 'intelligence.schema' => 'core',
        'intelligence.patterns.enabled' => false, 'intelligence.knn.min_train_size' => 36,
        'intelligence.knn.test_size' => 12, 'intelligence.knn.min_validation_rows' => 5,
        'intelligence.knn.min_directional_predictions' => 1]);
});
afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

function recoveryMarket(User $user): Market
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m']);

    return $market;
}

it('builds ordinary intelligence with both optional human modes disabled and no opinions', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['human_training.trend_enabled' => false, 'human_training.candle_enabled' => false]);
    $manifest = IntelligenceFixtures::snapshot();
    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);
    expect($report['human_guidance']['status'])->toBe('disabled')
        ->and($report['candle_guidance']['status'])->toBe('disabled')
        ->and($report['human_keys'])->toBe([])->and($report['candle_keys'])->toBe([])
        ->and($report['status'])->toBe('ready');
});

it('records exact dirty ranges without consuming later revisions', function () {
    $market = recoveryMarket(User::factory()->create());
    $changes = app(HistoryChanges::class);
    expect($changes->record($market->market_id, '1m', 300000, 360000))->toBe(1);
    expect($changes->record($market->market_id, '1m', 120000, 180000))->toBe(2);
    $state = DB::table('market_history_backfills')->where('market_id', $market->market_id)->first();
    expect($changes->replayStart($state, 1))->toBe(300000)
        ->and($changes->replayStart($state, 2))->toBe(120000)
        ->and((int) $state->trained_revision)->toBe(0);
    DB::table('market_history_backfills')->where('history_id', $state->history_id)->update(['trained_revision' => 1]);
    $state = DB::table('market_history_backfills')->where('history_id', $state->history_id)->first();
    expect($changes->replayStart($state, 2))->toBe(120000);
});

it('uses a full replay when an older writer did not record a range', function () {
    $market = recoveryMarket(User::factory()->create());
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', 300000, 360000);
    DB::table('market_history_backfills')->where('market_id', $market->market_id)->increment('history_revision');
    $changes->record($market->market_id, '1m', 120000, 180000);
    $state = DB::table('market_history_backfills')->where('market_id', $market->market_id)->first();
    expect($changes->replayStart($state, 3))->toBeNull();
});

it('keeps the first debounce deadline while combining additional repair pages', function () {
    $this->freezeTime();
    $market = recoveryMarket(User::factory()->create());
    $changes = app(HistoryChanges::class);
    $changes->record($market->market_id, '1m', 300000, 360000);
    $first = DB::table('market_history_backfills')->where('market_id', $market->market_id)->value('build_next_attempt_at');
    $this->travel(10)->seconds();
    $changes->record($market->market_id, '1m', 120000, 180000);
    expect(DB::table('market_history_backfills')->where('market_id', $market->market_id)->value('build_next_attempt_at'))->toBe($first);
});

it('reuses unchanged legacy snapshots and preserves their submitted labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $old = HumanTrainingSnapshot::factory()->create(['dataset_id' => $manifest['dataset_id']]);
    $user = User::factory()->create();
    HumanCandleLabel::factory()->create(['snapshot_id' => $old->snapshot_id, 'trainer_id' => $user->user_id, 'action' => 'hold']);
    $payload = $old->verifiedPayload();
    $payload['model_observation'] = ['action' => 'buy'];
    $resolved = app(SnapshotRevisions::class)->resolve($manifest, [$old->decision_at_ms => $payload]);
    expect($resolved[$old->decision_at_ms]->snapshot_id)->toBe($old->snapshot_id);
    $this->assertDatabaseCount('human_training_snapshots', 1);
    $this->assertDatabaseCount('human_candle_labels', 1);
});

it('creates one replacement revision after a chart correction without copying old labels', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    $manifest = IntelligenceFixtures::snapshot();
    $old = HumanTrainingSnapshot::factory()->create(['dataset_id' => $manifest['dataset_id']]);
    $user = User::factory()->create();
    HumanCandleLabel::factory()->create(['snapshot_id' => $old->snapshot_id, 'trainer_id' => $user->user_id, 'action' => 'hold']);
    $original = $old->verifiedPayload();
    $corrected = $original;
    $corrected['series'][0]['close'] = '10.5';
    $service = app(SnapshotRevisions::class);
    $new = $service->resolve($manifest, [$old->decision_at_ms => $corrected])[$old->decision_at_ms];
    expect($new->snapshot_id)->not->toBe($old->snapshot_id)
        ->and($new->payload['revision']['previous_snapshot_id'])->toBe($old->snapshot_id)
        ->and($new->payload['revision']['requires_review'])->toBeTrue()
        ->and($new->candleLabels()->count())->toBe(0)
        ->and($old->fresh()->verifiedPayload())->toBe($original);
    expect($service->resolve($manifest, [$old->decision_at_ms => $corrected])[$old->decision_at_ms]->snapshot_id)->toBe($new->snapshot_id);
    $this->assertDatabaseCount('human_training_snapshots', 2);
    $this->assertDatabaseCount('human_candle_labels', 1);
});

it('rejects corrupted frozen snapshots rather than replacing or inheriting their labels', function () {
    $manifest = IntelligenceFixtures::snapshot();
    $old = HumanTrainingSnapshot::factory()->create(['dataset_id' => $manifest['dataset_id']]);
    $payload = $old->payload;
    DB::table('human_training_snapshots')->where('snapshot_id', $old->snapshot_id)->update(['sha256' => str_repeat('0', 64)]);
    expect(fn () => app(SnapshotRevisions::class)->resolve($manifest, [$old->decision_at_ms => $payload]))->toThrow(LogicException::class);
});

it('refuses owner recovery actions from ordinary users', function () {
    Bus::fake();
    $owner = User::factory()->create();
    $ordinary = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($ordinary);
    $this->actingAs($ordinary)->get(route('owner.history-recovery.show', $market))->assertForbidden();
    $this->actingAs($ordinary)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('owner.history-recovery.store', $market))->assertForbidden();
    Bus::assertNothingDispatched();
});

it('coalesces repeated owner recovery requests into one queued job', function () {
    Bus::fake([RecoverMarketHistory::class]);
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);
    $service = app(HistoryRecovery::class);
    $first = $service->request($market);
    expect($service->request($market))->toBe($first);
    $this->assertDatabaseCount('history_recovery_requests', 1);
    Bus::assertDispatchedTimes(RecoverMarketHistory::class, 1);
    Bus::assertDispatched(RecoverMarketHistory::class, fn ($job) => $job->queue === 'history');
});

it('blocks synchronous or disabled recovery without mutating history', function () {
    $market = recoveryMarket(User::factory()->create());
    config(['queue.default' => 'sync']);
    expect(fn () => app(HistoryRecovery::class)->request($market))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('history_recovery_requests', 0);
    $this->assertDatabaseCount('market_history_changes', 0);
});
