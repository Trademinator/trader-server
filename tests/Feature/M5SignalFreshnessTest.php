<?php

use App\Domain\Intelligence\KnnEnsemble;
use App\Domain\Intelligence\ModelStore;
use App\Models\ClientApiKey;
use App\Models\ClientMarketSetting;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function f6Bearer(User $user): string
{
    $secret = 'tmk_'.str_repeat('f', 43);
    ClientApiKey::query()->create([
        'user_id' => $user->user_id, 'label' => 'f6', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return $secret;
}

function f6Market(User $user, string $period, int $decisionAtMs, string $referencePrice = '100', array $extraPayload = []): array
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->getKey(), 'selected_period' => $period, 'status' => 'active',
    ]);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    ClientMarketSetting::query()->create([
        'user_id' => $user->getKey(), 'market_subscription_id' => $subscription->getKey(),
        'trading_enabled' => true, 'paper_enabled' => false, 'max_order_quote' => 100,
        'max_position_quote' => null, 'reserve_quote' => 0, 'max_spread_bps' => 50,
        'max_taker_fee_bps' => 50, 'max_signal_drift_bps' => 100, 'min_signal_confidence' => 0.6,
        'block_conflicting_exposure' => true, 'paper_initial_quote' => 1000,
        'alert_on_signal_change' => false, 'alert_on_execution_failure' => false, 'digest_frequency' => 'off',
    ]);

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $marketKey = ModelStore::marketKey('kraken', 'BTC/USD', $period);
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $marketKey,
        'status' => 'ready', 'generation_key' => null, 'sha256' => str_repeat('a', 64),
        'report' => '{}', 'created_at' => now(),
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $marketKey, 'model_id' => $model, 'updated_at' => now(),
    ]);
    $signal = MarketSignal::query()->create([
        'market_id' => $market->getKey(), 'snapshot_key' => hash('sha256', $model.$decisionAtMs),
        'period' => $period, 'model_id' => $model, 'decision_at_ms' => $decisionAtMs,
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => true,
        'action' => 'buy', 'reason' => 'supported',
        'payload' => ['confidence' => 0.8, 'evidence_score' => 0.8,
            'reference_price' => $referencePrice, 'reference_price_source' => 'closed_candle_close', ...$extraPayload],
    ]);

    return [f6Bearer($user), $subscription, $signal];
}

function f6DecisionState(float $price): array
{
    return [
        'reported_at_ms' => now()->getTimestampMs(),
        'quote_balance' => 1000,
        'base_balance' => 0,
        'position_quote' => 0,
        'best_bid' => $price,
        'best_ask' => $price,
        'taker_fee_bps' => 10,
    ];
}

it('caps coarse-period signals at the wall clock maximum', function () {
    $this->freezeTime();
    config([
        'intelligence.max_signal_age_periods' => 2,
        'intelligence.max_signal_age_seconds' => 86400,
    ]);
    $user = User::factory()->create();
    [$secret, $subscription] = f6Market($user, '1w', now()->subHours(25)->getTimestampMs());

    $this->withToken($secret)
        ->postJson('/api/v1/client/markets/'.$subscription->getKey().'/decision', f6DecisionState(100))
        ->assertOk()
        ->assertJsonPath('eligible', false)
        ->assertJsonPath('reason', 'stale_signal');
});

it('returns the saved independent model scores in decision and market payloads', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $prediction = ['action' => 'buy', 'reason' => 'supported', 'confidence' => 1.0,
        'votes' => ['buy' => 1.0, 'hodl' => 0.0, 'sell' => 0.0],
        'similarity' => 1.0, 'neighbors' => 9, 'effective_neighbors' => 9.0];
    $result = app(KnnEnsemble::class)->combine($prediction, $prediction, config('intelligence.ensemble'));
    [$secret, $subscription] = f6Market($user, '1h', now()->subMinutes(30)->getTimestampMs(), extraPayload: ['scoring' => $result['scoring']]);

    $this->withToken($secret)->postJson('/api/v1/client/markets/'.$subscription->getKey().'/decision', f6DecisionState(100))
        ->assertOk()->assertJsonPath('eligible', true)
        ->assertJsonPath('action_meaning', 'supported_buy_by_weighted_models')
        ->assertJsonPath('server_signal.scoring', json_decode(json_encode($result['scoring']), true))
        ->assertJsonMissingPath('server_signal.scoring.knowledge');
    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()->assertJsonPath('market.signal.scoring.components.human_candle.action', 'buy')
        ->assertJsonPath('market.signal.scoring.effective_weights.human_candle', 0.6)
        ->assertJsonPath('market.signal.action_meaning', 'supported_buy_by_weighted_models');
});

it('rejects excessive drift from the signal reference price and accepts bounded drift', function () {
    $this->freezeTime();
    config([
        'intelligence.max_signal_age_periods' => 2,
        'intelligence.max_signal_age_seconds' => 86400,
    ]);
    $user = User::factory()->create();
    [$secret, $subscription, $signal] = f6Market($user, '1h', now()->subMinutes(30)->getTimestampMs());

    $url = '/api/v1/client/markets/'.$subscription->getKey().'/decision';
    $this->withToken($secret)->postJson($url, f6DecisionState(102))
        ->assertOk()
        ->assertJsonPath('eligible', false)
        ->assertJsonPath('reason', 'signal_price_drift')
        ->assertJsonPath('risk.signal_drift_bps', 200);

    $this->withToken($secret)->postJson($url, f6DecisionState(100.5))
        ->assertOk()
        ->assertJsonPath('eligible', true)
        ->assertJsonPath('reason', 'eligible')
        ->assertJsonPath('action', 'buy')
        ->assertJsonPath('action_meaning', 'supported_bottom_with_upward_future_move')
        ->assertJsonPath('server_signal.action_meaning', 'supported_bottom_with_upward_future_move')
        ->assertJsonPath('server_signal.reference_price', '100')
        ->assertJsonPath('server_signal.reference_price_source', 'closed_candle_close')
        ->assertJsonPath('risk.signal_drift_bps', 50);

    $marketResponse = $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey())
        ->assertOk()
        ->assertJsonPath('market.signal.action_meaning', 'supported_bottom_with_upward_future_move')
        ->assertJsonPath('market.signal.reference_price', '100')
        ->assertJsonPath('market.signal.reference_price_source', 'closed_candle_close');

    expect((float) $marketResponse->json('market.settings.max_signal_drift_bps'))->toBe(100.0);
    expect($signal->fresh()->payload['reference_price'])->toBe('100');
});
