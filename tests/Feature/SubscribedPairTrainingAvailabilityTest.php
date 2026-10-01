<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;

it('keeps a subscribed pair visible and disabled when its training dataset is not ready', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id,
        'symbol' => 'BTC/USD',
        'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '5m']);
    MarketSubscription::query()->create([
        'user_id' => $user->user_id,
        'market_id' => $market->market_id,
        'active' => true,
    ]);

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :datasets="$datasets" :show-unavailable-datasets="true" />',
        ['datasets' => []],
    );
    $html = (string) $view;

    $view->assertSeeText('Bitso · BTC/USD · 5m · Subscribed — training dataset not ready');
    expect($html)->toContain('disabled')->toContain('color:#15803d');
});
