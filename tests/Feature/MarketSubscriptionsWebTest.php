<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;

it('requires sign in and restricts unsubscribe to the subscription owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $item = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);

    $this->get(route('markets.index'))->assertRedirect(route('login'));
    $this->actingAs($other)->delete(route('markets.destroy', $item->market_subscription_id))->assertNotFound();
    expect($item->fresh()->active)->toBeTrue();
    $this->actingAs($owner)->delete(route('markets.destroy', $item->market_subscription_id))->assertRedirect(route('markets.index'));
    expect($item->fresh()->active)->toBeFalse();
});

it('lets a signed-in user subscribe once to a configured market', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $repository = Mockery::mock(\App\Repositories\ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->twice()->with(Mockery::type(Exchange::class));
    $repository->shouldReceive('markets')->twice()->andReturn(['BTC/USD' => []]);
    app()->instance(\App\Repositories\ExchangeRepository::class, $repository);

    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ])->assertRedirect(route('markets.index'));
    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'tick_size' => '0.01',
    ])->assertRedirect(route('markets.index'));
    expect(Market::query()->count())->toBe(1)->and(MarketSubscription::query()->count())->toBe(1);
});
