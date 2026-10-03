<?php

use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\ClientExecutionReport;
use App\Models\ClientMarketSetting;
use App\Models\ClientPaperEvent;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function m5Bearer(User $user): string
{
    $secret = 'tmk_'.str_repeat('a', 43);
    ClientApiKey::query()->create([
        'user_id' => $user->user_id, 'label' => 'test', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

function m5Market(User $user, bool $active = true): MarketSubscription
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1h', 'status' => 'active']);

    return MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => $active]);
}

function m5PaperMarket(User $user): array
{
    $secret = m5Bearer($user);
    $subscription = m5Market($user);
    ClientMarketSetting::query()->create([
        'user_id' => $user->user_id, 'market_subscription_id' => $subscription->getKey(),
        'trading_enabled' => false, 'paper_enabled' => true, 'max_order_quote' => 100,
        'max_position_quote' => null, 'reserve_quote' => 0, 'max_spread_bps' => 5,
        'max_taker_fee_bps' => 50, 'min_signal_confidence' => 0.6,
        'block_conflicting_exposure' => true, 'paper_initial_quote' => 1000,
        'alert_on_signal_change' => false, 'alert_on_execution_failure' => false, 'digest_frequency' => 'off',
    ]);

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', '1h');
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'status' => 'ready', 'generation_key' => null, 'sha256' => str_repeat('d', 64),
        'report' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey, 'model_id' => $model, 'updated_at' => now(),
    ]);
    $signal = MarketSignal::query()->create([
        'market_id' => $subscription->market_id, 'snapshot_key' => str_repeat('e', 64), 'period' => '1h',
        'model_id' => $model, 'decision_at_ms' => now()->subMinutes(30)->getTimestampMs(),
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true,
        'action' => 'buy', 'reason' => 'supported',
        'payload' => ['confidence' => 0.8, 'evidence_score' => 0.8],
    ]);

    return [$secret, $subscription, $signal];
}

it('requires a valid bearer token for the versioned client api', function () {
    $this->getJson('/api/v1/client/markets')->assertUnauthorized();

    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $this->withToken($secret)->getJson('/api/v1/client/markets')
        ->assertOk()->assertJsonPath('api_version', 1);
});

it('continues to authenticate migrated legacy uuid bearer keys', function () {
    $user = User::factory()->create();
    $secret = (string) Str::uuid();
    ClientApiKey::query()->create([
        'user_id' => $user->user_id, 'label' => 'legacy', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    $this->withToken($secret)->getJson('/api/v1/client/markets')
        ->assertOk()->assertJsonPath('api_version', 1);
});

it('rate limits repeated failed client api authentication attempts by source ip', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40']);
    $invalid = 'tmk_'.str_repeat('z', 43);

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->withToken($invalid)->getJson('/api/v1/client/markets')->assertUnauthorized();
    }

    $this->withToken($invalid)->getJson('/api/v1/client/markets')
        ->assertStatus(429)->assertJsonPath('error.code', 'authentication_rate_limited')
        ->assertHeader('Retry-After');
});

it('does not charge successful client requests against the authentication failure limiter', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41']);
    $user = User::factory()->create();
    $secret = m5Bearer($user);

    for ($request = 0; $request < 31; $request++) {
        $this->withToken($secret)->getJson('/api/v1/client/markets')->assertOk();
    }
});

it('keeps trading disabled until explicitly enabled per market', function () {
    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $subscription = m5Market($user);

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.settings.trading_enabled', false)
        ->assertJsonPath('market.settings.paper_enabled', false);
});

it('rejects enabling trading on an inactive subscription', function () {
    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $subscription = m5Market($user, false);

    $this->withToken($secret)->putJson('/api/v1/client/markets/'.$subscription->getKey().'/settings', [
        'trading_enabled' => true, 'paper_enabled' => false, 'max_order_quote' => 100,
        'max_position_quote' => 500, 'reserve_quote' => 10, 'max_spread_bps' => 50,
        'max_taker_fee_bps' => 50, 'min_signal_confidence' => 0.6, 'block_conflicting_exposure' => true,
        'paper_initial_quote' => 10000, 'alert_on_signal_change' => false,
        'alert_on_execution_failure' => false, 'digest_frequency' => 'off',
    ])->assertStatus(409)->assertJsonPath('error.code', 'subscription_inactive');
});

it('executes a paper signal at most once even when a retry uses a new idempotency key', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    [$secret, $subscription, $signal] = m5PaperMarket($user);
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    $input = [
        'reported_at_ms' => now()->getTimestampMs(), 'best_bid' => 100, 'best_ask' => 100,
        'taker_fee_bps' => 10,
    ];

    $this->withToken($secret)->postJson($url, [...$input, 'idempotency_key' => 'paper-first-0001'])
        ->assertOk()->assertJsonPath('event', 'executed')->assertJsonPath('reason', 'paper_fill')
        ->assertJsonPath('signal_id', $signal->getKey());
    $this->withToken($secret)->postJson($url, [...$input, 'idempotency_key' => 'paper-second-0002'])
        ->assertOk()->assertJsonPath('event', 'skipped')->assertJsonPath('reason', 'signal_already_acted')
        ->assertJsonPath('decision.eligible', false)->assertJsonPath('decision.reason', 'signal_already_acted');

    expect(ClientPaperEvent::query()->where('market_signal_id', $signal->getKey())->count())->toBe(2)
        ->and(ClientPaperEvent::query()->where('market_signal_id', $signal->getKey())
            ->where('event', 'executed')->count())->toBe(1);
});

it('allows a skipped paper signal to be reevaluated before any paper fill executes', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    [$secret, $subscription, $signal] = m5PaperMarket($user);
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    $base = ['reported_at_ms' => now()->getTimestampMs(), 'taker_fee_bps' => 10];

    $this->withToken($secret)->postJson($url, [
        ...$base, 'idempotency_key' => 'paper-wide-0001', 'best_bid' => 99, 'best_ask' => 100,
    ])->assertOk()->assertJsonPath('event', 'skipped')->assertJsonPath('reason', 'spread_too_wide');
    $this->withToken($secret)->postJson($url, [
        ...$base, 'idempotency_key' => 'paper-tight-0002', 'best_bid' => 100, 'best_ask' => 100,
    ])->assertOk()->assertJsonPath('event', 'executed')->assertJsonPath('reason', 'paper_fill');

    expect(ClientPaperEvent::query()->where('market_signal_id', $signal->getKey())
        ->where('event', 'executed')->count())->toBe(1);
});

it('stores client execution reports idempotently and rejects changed reuse', function () {
    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $subscription = m5Market($user);
    $signal = MarketSignal::query()->create([
        'market_id' => $subscription->market_id, 'snapshot_key' => str_repeat('b', 64), 'period' => '1h',
        'model_id' => null, 'decision_at_ms' => now()->subHour()->getTimestampMs(), 'recorded_at_ms' => now()->getTimestampMs(),
        'is_change' => true, 'action' => 'hodl', 'reason' => 'supported', 'payload' => ['confidence' => 0.7],
    ]);
    $payload = ['idempotency_key' => 'report-0001', 'signal_id' => $signal->getKey(), 'event' => 'skipped',
        'reason' => 'client_risk_limit', 'occurred_at_ms' => now()->getTimestampMs()];

    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/reports', $payload)->assertCreated();
    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/reports', $payload)->assertCreated();
    expect(ClientExecutionReport::query()->count())->toBe(1);

    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/reports', [
        ...$payload, 'reason' => 'different_reason',
    ])->assertStatus(409);
});

it('allows only protective close reporting after a subscription becomes inactive', function () {
    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $subscription = m5Market($user, false);
    $signal = MarketSignal::query()->create([
        'market_id' => $subscription->market_id, 'snapshot_key' => str_repeat('c', 64), 'period' => '1h',
        'model_id' => null, 'decision_at_ms' => now()->subHour()->getTimestampMs(), 'recorded_at_ms' => now()->getTimestampMs(),
        'is_change' => true, 'action' => 'buy', 'reason' => 'supported', 'payload' => ['confidence' => 0.8],
    ]);

    $base = ['signal_id' => $signal->getKey(), 'event' => 'fill', 'side' => 'sell', 'quantity' => 0.1, 'price' => 50000,
        'occurred_at_ms' => now()->getTimestampMs()];
    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/reports', [
        ...$base, 'idempotency_key' => 'inactive-new',
    ])->assertStatus(409);
    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/reports', [
        ...$base, 'idempotency_key' => 'inactive-protect', 'protective' => true,
    ])->assertCreated();
});
