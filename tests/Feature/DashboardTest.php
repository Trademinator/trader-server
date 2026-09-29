<?php

use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk()->assertHeader('X-Trademinator-Trace')->assertDontSee('Server administration');

    config(['operations.owner_uuid' => $user->getKey()]);
    $this->get('/dashboard')->assertOk()->assertHeader('X-Trademinator-Trace')->assertSee('Server administration');
    $this->get(route('owner.overview'))->assertOk();
});

it('requires verification and scopes dashboard charts to active subscriptions of the current user', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    $market->exchange->update(['name' => '<script>privateExchange()</script>']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $sub = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    $url = route('dashboard.chart', $sub->getKey());
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect(route('verification.notice'));
    $this->actingAs($other)->getJson($url)->assertNotFound();
    $this->get(route('dashboard', ['subscription' => $sub->getKey()]))->assertNotFound();
    $this->get('/dashboard')->assertOk()->assertDontSee('BTC/USD')->assertDontSee('privateExchange');
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('BTC/USD')->assertSee('Waiting for evidence')
        ->assertSee('does not enable trading')->assertSee('Unknown')->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>privateExchange()</script>', false)->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson($url)->assertOk()->assertJsonPath('subscription_id', $sub->getKey());
    $sub->update(['active' => false]);
    $this->getJson($url)->assertNotFound();
    $this->get('/dashboard')->assertDontSee('BTC/USD');
});

it('renders only closed valid candles in the selected feed period and never trades or subscribes on a read', function () {
    $this->travelTo('2026-09-29 12:10:00 UTC');
    Queue::fake();
    $owner = User::factory()->create();
    $signal = MarketSignal::factory()->create();
    $market = $signal->market;
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    $sub = MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
    foreach ([[-240000, '1m', 95], [-180000, '1m', 0], [-120000, '1m', 100], [-60000, '1m', 101], [0, '1m', 999], [-86400000, '1d', 888]] as [$offset, $period, $close]) {
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => $period,
            'microtimestamp' => now()->getTimestampMs() + $offset,
            'payload' => json_encode(['open' => $close, 'close' => $close, 'high' => $close, 'low' => $close, 'volume' => 0])]);
    }
    $this->actingAs($owner)->getJson(route('dashboard.chart', $sub->getKey()))->assertOk()
        ->assertJsonCount(3, 'chart.series')->assertJsonPath('chart.series.2.close', 101)
        ->assertJsonPath('chart.stale', false)->assertJsonPath('chart.series.2.volume', 0)
        ->assertJsonPath('chart.invalid_candles', 1)->assertJsonPath('chart.gaps', 1);
    $this->get('/dashboard')->assertOk()->assertSee('Potential training rows');
    $this->assertDatabaseCount('market_subscriptions', 1);
    $this->assertDatabaseCount('market_signals', 1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps the previous visit boundary stable during refresh and counts only this users new changes', function () {
    $this->freezeTime();
    $owner = User::factory()->create();
    $owner->forceFill(['dashboard_seen_at_ms' => now()->subHour()->getTimestampMs()])->save();
    $signal = MarketSignal::factory()->create();
    MarketSubscription::query()->create(['user_id' => $owner->getKey(), 'market_id' => $signal->market_id, 'active' => true]);
    MarketSignal::factory()->create(['market_id' => $signal->market_id, 'recorded_at_ms' => now()->subHours(2)->getTimestampMs()]);
    MarketSignal::factory()->create(['market_id' => $signal->market_id, 'is_change' => false]);
    MarketSignal::factory()->create();
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertViewHas('changeCount', 1);
    $this->get('/dashboard')->assertOk()->assertViewHas('changeCount', 1);
    expect($owner->fresh()->dashboard_seen_at_ms)->toBe(now()->getTimestampMs());
});
