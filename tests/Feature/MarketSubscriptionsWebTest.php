<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;

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
    $repository->shouldReceive('setExchange')->times(3)->with(Mockery::type(Exchange::class));
    $repository->shouldReceive('describe')->once()->andReturn([
        'name' => 'Kraken', 'timeframes' => ['1m' => '1m'], 'precisionMode' => \ccxt\TICK_SIZE,
    ]);
    $repository->shouldReceive('periods')->twice()->andReturn(['1m' => '1m']);
    $repository->shouldReceive('markets')->times(3)->andReturn([
        'BTC/USD' => ['spot' => true, 'precision' => ['price' => 0.01]],
    ]);
    app()->instance(\App\Repositories\ExchangeRepository::class, $repository);

    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'tick_size' => '999',
    ])->assertRedirect(route('markets.index'));
    $this->actingAs($user)->post(route('markets.store'), [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD',
    ])->assertRedirect(route('markets.index'));
    expect(Market::query()->count())->toBe(1)->and(MarketSubscription::query()->count())->toBe(1)
        ->and(bccomp((string) Market::query()->first()->tick_size, '0.01', 18))->toBe(0);
});

it('groups subscriptions by exchange name with small logos and sorts pairs', function () {
    $user = User::factory()->create();
    $zeta = Exchange::query()->create(['name' => 'Zeta ID', 'class' => 'coinbase', 'config' => '{}']);
    $alpha = Exchange::query()->create(['name' => 'Alpha ID', 'class' => 'kraken', 'config' => '{}']);
    foreach ([[$zeta, 'ETH/USD', '1m'], [$alpha, 'BTC/USD', '15m'], [$alpha, 'ADA/USD', '1m']] as [$exchange, $symbol, $period]) {
        $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
        MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
        MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);
    }
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->twice();
    $repository->shouldReceive('describe')->twice()->andReturn(
        ['name' => 'Zeta Exchange', 'timeframes' => ['1m' => '1m'], 'urls' => ['logo' => 'https://example.com/zeta.png']],
        ['name' => 'Alpha Exchange', 'timeframes' => ['1m' => '1m'], 'urls' => ['logo' => 'https://example.com/alpha.png']],
    );
    app()->instance(ExchangeRepository::class, $repository);

    $response = $this->actingAs($user)->get(route('markets.index'))->assertOk();
    $list = explode('<h2>Your markets</h2>', $response->getContent(), 2)[1];
    expect($list)->toContain('width="32" height="32"')
        ->toContain('https://example.com/alpha.png')->toContain('https://example.com/zeta.png');
    expect(strpos($list, 'Alpha Exchange'))->toBeLessThan(strpos($list, 'ADA/USD'));
    expect(strpos($list, 'ADA/USD'))->toBeLessThan(strpos($list, 'BTC/USD'));
    expect(strpos($list, 'BTC/USD'))->toBeLessThan(strpos($list, 'Zeta Exchange'));
    expect(strpos($list, 'Zeta Exchange'))->toBeLessThan(strpos($list, 'ETH/USD'));
    expect($list)->toContain('BTC/USD · 15m');
});
