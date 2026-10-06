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
        ->assertDontSeeText(substr($aaa, 0, 8))
        ->assertDontSeeText(substr($zzz, 0, 8))
        ->assertDontSee($otherDataset, false)->assertDontSeeText('OTHER/USD');
});

it('collapses repeated frozen datasets into one logical market option by default', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Binance', 'class' => 'binance', 'config' => '{}']);
    subscribedPairSelectMarket($user, $exchange, 'ETH/BTC', '3m');

    $newest = (string) Str::uuid7();
    $older = (string) Str::uuid7();
    $datasets = [
        ['dataset_id' => $newest, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3988],
        ['dataset_id' => $older, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3900],
    ];

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :datasets="$datasets" />',
        ['datasets' => $datasets],
    );
    $html = (string) $view;

    $view->assertSeeText('Binance · ETH/BTC · 3m · 3,988 samples')
        ->assertDontSeeText(substr($newest, 0, 8))->assertDontSeeText(substr($older, 0, 8));
    expect(substr_count($html, 'Binance · ETH/BTC · 3m · 3,988 samples'))->toBe(1)
        ->and($html)->toContain('value="'.$newest.'"')->not->toContain('value="'.$older.'"');
});

it('can hide dataset sample counts when they are not pertinent to the workflow', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Binance', 'class' => 'binance', 'config' => '{}']);
    subscribedPairSelectMarket($user, $exchange, 'ETH/BTC', '3m');

    $dataset = (string) Str::uuid7();
    $datasets = [
        ['dataset_id' => $dataset, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3988],
    ];

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :datasets="$datasets" :show-dataset-samples="false" />',
        ['datasets' => $datasets],
    );

    $view->assertSeeText('Binance · ETH/BTC · 3m')
        ->assertDontSeeText('3,988 samples');
});

it('keeps the currently open frozen dataset as the single option for its market', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Binance', 'class' => 'binance', 'config' => '{}']);
    subscribedPairSelectMarket($user, $exchange, 'ETH/BTC', '3m');

    $newest = (string) Str::uuid7();
    $current = (string) Str::uuid7();
    $datasets = [
        ['dataset_id' => $newest, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3988],
        ['dataset_id' => $current, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3800],
    ];
    $currentDataset = $datasets[1];

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :value="$current" :datasets="$datasets" :current-dataset="$currentDataset" />',
        compact('current', 'datasets', 'currentDataset'),
    );
    $html = (string) $view;

    $view->assertSeeText('Binance · ETH/BTC · 3m · 3,800 samples');
    expect(substr_count($html, 'ETH/BTC · 3m'))->toBe(1)
        ->and($html)->toContain('value="'.$current.'" selected')->not->toContain('value="'.$newest.'"');
});

it('can explicitly expose frozen dataset versions when they are useful', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Binance', 'class' => 'binance', 'config' => '{}']);
    subscribedPairSelectMarket($user, $exchange, 'ETH/BTC', '3m');

    $first = (string) Str::uuid7();
    $second = (string) Str::uuid7();
    $datasets = [
        ['dataset_id' => $first, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3988],
        ['dataset_id' => $second, 'exchange' => 'binance', 'symbol' => 'ETH/BTC', 'period' => '3m', 'rows' => 3900],
    ];

    $view = $this->actingAs($user)->blade(
        '<x-subscribed-pair-select name="dataset" :datasets="$datasets" :show-dataset-versions="true" />',
        ['datasets' => $datasets],
    );

    $view->assertSeeText('Binance · ETH/BTC · 3m · 3,988 samples · '.substr($first, 0, 8))
        ->assertSeeText('Binance · ETH/BTC · 3m · 3,900 samples · '.substr($second, 0, 8));
});
