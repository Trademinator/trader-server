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
    expect($report['human_guidance']['status'])->toBe('outcome_training_disabled')
        ->and($report['candle_guidance']['status'])->toBe('disabled')
        ->and($report['human_keys'])->toBe([])->and($report['candle_keys'])->toBe([])
        ->and($report['status'])->toBe('ready');
});

it('excludes disabled Trend guidance while leaving Candle guidance available', function () {
    $this->travelTo('2024-01-01 04:10:00 UTC');
    config(['human_training.enabled' => true, 'human_training.trend_enabled' => false,
        'human_training.candle_enabled' => true]);
    $manifest = IntelligenceFixtures::snapshot();

    $report = app(IntelligenceTrainer::class)->train($manifest['dataset_id']);

    expect($report['human_guidance']['status'])->toBe('outcome_training_disabled');
    expect($report['human_guidance']['influence'])->toBeFalse();
    expect($report['human_keys'])->toBe([]);
    expect($report['candle_guidance']['status'])->toBe('insufficient_candle_labels');
    expect($report['status'])->toBe('ready');
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

it('queues the original repair when its password form is confirmed', function (array $options, string $retry) {
    $this->freezeTime();
    Bus::fake([RecoverMarketHistory::class]);
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);
    $url = route('owner.history-recovery.store', $market);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->get(route('owner.history-recovery.show', $market))->assertOk();

    $confirmation = $this->from(route('owner.history-recovery.show', $market))->post($url, $options);

    $confirmation->assertOk()->assertViewIs('auth.confirm-password')
        ->assertSee('action="'.$url.'"', false)
        ->assertSee('name="retry_unavailable" value="'.$retry.'"', false)
        ->assertSee('Confirm and repair history');
    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();

    $queued = $this->post($confirmation->viewData('formAction'), [
        ...$confirmation->viewData('formFields'), 'password' => 'password',
    ]);

    $requestId = DB::table('history_recovery_requests')->value('request_id');
    $queued->assertRedirectToRoute('owner.history-recovery.show', $market)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('auth.password_confirmed_at', now()->timestamp)
        ->assertSessionHas('status', 'Recovery request '.$requestId.' queued. The stages below update as the existing workers process it.');
    $this->assertDatabaseHas('history_recovery_requests', [
        'request_id' => $requestId, 'market_id' => $market->market_id,
        'retry_unavailable' => (int) $retry, 'status' => 'queued',
    ]);
    $this->get($queued->headers->get('Location'))->assertSee('Recovery request '.$requestId.' queued.');
    $this->get(route('owner.history-recovery.show', $market))->assertOk();
    $this->post($url, [...$options, 'password' => 'password'])
        ->assertRedirectToRoute('owner.history-recovery.show', $market);
    $this->assertDatabaseCount('history_recovery_requests', 1);
    Bus::assertDispatchedTimes(RecoverMarketHistory::class, 1);
    Bus::assertDispatched(RecoverMarketHistory::class, fn ($job) => $job->requestId === $requestId);
})->with([
    'retry unchecked' => [[], '0'],
    'retry checked' => [['retry_unavailable' => '1'], '1'],
]);

it('queues recovery immediately while password confirmation is recent', function () {
    $this->freezeTime();
    Bus::fake([RecoverMarketHistory::class]);
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id, 'auth.password_timeout' => 60]);
    $market = recoveryMarket($owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->subSeconds(30)->timestamp])
        ->post(route('owner.history-recovery.store', $market))
        ->assertRedirectToRoute('owner.history-recovery.show', $market);

    $this->assertDatabaseCount('history_recovery_requests', 1);
    Bus::assertDispatchedTimes(RecoverMarketHistory::class, 1);
});

it('asks for the password when the configured confirmation timeout expires', function () {
    $this->freezeTime();
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id, 'auth.password_timeout' => 60]);
    $market = recoveryMarket($owner);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('owner.history-recovery.show', $market))->assertOk();
    $this->travel(61)->seconds();

    $this->post(route('owner.history-recovery.store', $market))->assertViewIs('auth.confirm-password');

    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
});

it('keeps invalid password attempts on the repair confirmation without queueing', function (mixed $password, string $message) {
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->post(route('owner.history-recovery.store', $market), [
            'password' => $password, 'retry_unavailable' => '1',
        ])->assertUnprocessable()->assertViewIs('auth.confirm-password')
        ->assertSee($message)
        ->assertSee('name="retry_unavailable" value="1"', false)
        ->assertSessionHas('auth.password_confirmed_at', 0)
        ->assertSessionMissing('_old_input.password');

    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
})->with([
    'wrong password' => ['wrong-password', 'The password is incorrect.'],
    'empty password' => ['', 'The password field is required.'],
    'invalid password type' => [['password'], 'The password field must be a string.'],
]);

it('keeps JSON repair requests locked until password confirmation', function () {
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->postJson(route('owner.history-recovery.store', $market))
        ->assertStatus(423)->assertJsonPath('message', 'Password confirmation required.');

    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
});

it('rate limits repeated repair password guesses without queueing', function () {
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0]);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->post(route('owner.history-recovery.store', $market), ['password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->post(route('owner.history-recovery.store', $market), ['password' => 'password'])
        ->assertTooManyRequests()->assertSessionHas('auth.password_confirmed_at', 0);
    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
});

it('does not let a non-owner confirm a repair with their own valid password', function () {
    Bus::fake();
    $owner = User::factory()->create();
    $ordinary = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($ordinary);

    $this->actingAs($ordinary)->withSession(['auth.password_confirmed_at' => 0])
        ->post(route('owner.history-recovery.store', $market), ['password' => 'password'])
        ->assertForbidden()->assertSessionHas('auth.password_confirmed_at', 0);

    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
});

it('requires login before accepting a repair password', function () {
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);

    $this->post(route('owner.history-recovery.store', $market), ['password' => 'password'])
        ->assertRedirectToRoute('login');

    $this->assertDatabaseCount('history_recovery_requests', 0);
    Bus::assertNothingDispatched();
});

it('rejects invalid retry options before presenting the repair password form', function () {
    Bus::fake();
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $market = recoveryMarket($owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->from(route('owner.history-recovery.show', $market))
        ->post(route('owner.history-recovery.store', $market), ['retry_unavailable' => 'invalid'])
        ->assertSessionHasErrors(['retry_unavailable' => 'The retry unavailable field must be true or false.']);

    $this->assertDatabaseCount('history_recovery_requests', 0);
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
