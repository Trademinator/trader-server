<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Str;

function subscribedPairSelectMarket(User $user, Exchange $exchange, string $symbol, string $period, bool $active = true): Market
{
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id,
        'symbol' => $symbol,
        'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->market_id,
        'selected_period' => $period,
    ]);
    MarketSubscription::query()->create([
        'user_id' => $user->user_id,
        'market_id' => $market->market_id,
        'active' => $active,
    ]);

    return $market;
}

it('lists the current users active subscribed pairs in exchange pair period order by default', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $alpha = Exchange::query()->create(['name' => 'Alpha', 'class' => 'alpha', 'config' => '{}']);
    $zeta = Exchange::query()->create(['name' => 'Zeta', 'class' => 'zeta', 'config' => '{}']);

    subscribedPairSelectMarket($user, $zeta, 'AAA/USD', '1m');
    subscribedPairSelectMarket($user, $alpha, 'ZZZ/USD', '15m');
    subscribedPairSelectMarket($user, $alpha, 'BBB/USD', '1h');
    subscribedPairSelectMarket($user, $alpha, 'IGNORED/USD', '1m', false);
    subscribedPairSelectMarket($other, $alpha, 'OTHER/USD', '1m');

    $view = $this->actingAs($user)->blade('<x-subscribed-pair-select name="market" />');

    $view->assertSeeTextInOrder([
        'Alpha · BBB/USD · 1h',
        'Alpha · ZZZ/USD · 15m',
        'Zeta · AAA/USD · 1m',
    ])->assertDontSeeText('IGNORED/USD')->assertDontSeeText('OTHER/USD');
});

it('can include all active subscribed pairs and override sorting criteria and direction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $alpha = Exchange::query()->create(['name' => 'Alpha', 'class' => 'alpha', 'config' => '{}']);
    $zeta = Exchange::query()->create(['name' => 'Zeta', 'class' => 'zeta', 'config' => '{}']);

    $shared = subscribedPairSelectMarket($user, $zeta, 'ZZZ/USD', '1h');
    MarketSubscription::query()->create(['user_id' => $other->user_id, 'market_id' => $shared->market_id, 'active' => true]);
    subscribedPairSelectMarket($other, $alpha, 'AAA/USD', '1m');

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="market" :all-subscribed="true" :sort="$sort" />',
        ['sort' => ['exchange' => 'desc', 'pair' => 'asc', 'period' => 'asc']],
    );
    $html = (string) $view;

    $view->assertSeeTextInOrder(['Zeta · ZZZ/USD · 1h', 'Alpha · AAA/USD · 1m']);
    expect(substr_count($html, 'Zeta · ZZZ/USD · 1h'))->toBe(1);
});

it('uses dataset ids while applying subscription scope and custom pair-first sorting', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $alpha = Exchange::query()->create(['name' => 'Alpha', 'class' => 'alpha', 'config' => '{}']);
    $zeta = Exchange::query()->create(['name' => 'Zeta', 'class' => 'zeta', 'config' => '{}']);

    subscribedPairSelectMarket($user, $zeta, 'AAA/USD', '15m');
    subscribedPairSelectMarket($user, $alpha, 'ZZZ/USD', '1m');
    subscribedPairSelectMarket($other, $alpha, 'OTHER/USD', '1h');

    $aaa = (string) Str::uuid7();
    $zzz = (string) Str::uuid7();
    $otherDataset = (string) Str::uuid7();
    $datasets = [
        ['dataset_id' => $zzz, 'exchange' => 'alpha', 'symbol' => 'ZZZ/USD', 'period' => '1m', 'rows' => 20],
        ['dataset_id' => $aaa, 'exchange' => 'zeta', 'symbol' => 'AAA/USD', 'period' => '15m', 'rows' => 30],
        ['dataset_id' => $otherDataset, 'exchange' => 'alpha', 'symbol' => 'OTHER/USD', 'period' => '1h', 'rows' => 40],
    ];

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :datasets="$datasets" :sort="$sort" />',
        ['datasets' => $datasets, 'sort' => ['pair', 'exchange', 'period']],
    );

    $view->assertSeeTextInOrder(['Zeta · AAA/USD · 15m', 'Alpha · ZZZ/USD · 1m'])
        ->assertSee('value="'.$aaa.'"', false)->assertSee('value="'.$zzz.'"', false)
        ->assertDontSee($otherDataset, false)->assertDontSeeText('OTHER/USD');
});
