<?php

use App\Models\ClientApiKey;
use App\Models\ClientExecutionReport;
use App\Models\ClientMarketSetting;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;

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

it('requires a valid bearer token for the versioned client api', function () {
    $this->getJson('/api/v1/client/markets')->assertUnauthorized();

    $user = User::factory()->create();
    $secret = m5Bearer($user);
    $this->withToken($secret)->getJson('/api/v1/client/markets')
        ->assertOk()->assertJsonPath('api_version', 1);
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
