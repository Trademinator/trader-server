<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;

it('restricts intelligence pages to the active subscription owner and escapes market text', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => '<script>alert(1)</script>', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create(['user_id' => $owner->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $url = route('markets.intelligence', $subscription->getKey());

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->actingAs($owner)->get($url)->assertSee('HOLD')->assertSee('0.0%')
        ->assertSee('Training has not completed')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $subscription->update(['active' => false]);
    $this->get($url)->assertNotFound();
});
