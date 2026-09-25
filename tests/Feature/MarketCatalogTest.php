<?php

use App\Domain\MarketData\MarketCatalog;
use App\Models\Exchange;
use App\Models\User;
use App\Repositories\ExchangeRepository;

it('offers readable exchange names, spot pairs and only supported periods', function () {
    $exchange = Exchange::query()->create(['name' => 'kraken', 'class' => 'kraken', 'config' => '{}']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->twice()->with(Mockery::type(Exchange::class));
    $repository->shouldReceive('describe')->twice()->andReturn([
        'name' => 'Kraken', 'timeframes' => ['15m' => '15m', '1m' => '1m', '9m' => '9m'],
        'precisionMode' => \ccxt\TICK_SIZE,
    ]);
    $repository->shouldReceive('markets')->once()->andReturn([
        'BTC/USD' => ['spot' => true, 'precision' => ['price' => 0.01]],
        'ETH/USDT' => ['spot' => true, 'precision' => ['price' => 0.001]],
        'BTC/USD:USD' => ['spot' => false, 'precision' => ['price' => 0.1]],
    ]);
    app()->instance(ExchangeRepository::class, $repository);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('markets.index'))
        ->assertOk()->assertSee('<option value="kraken"', false)->assertSee('Kraken');
    $this->actingAs($user)->getJson(route('markets.options', 'kraken'))
        ->assertOk()->assertJsonPath('symbols.0.value', 'BTC/USD')
        ->assertJsonPath('symbols.0.tick_size', '0.01')
        ->assertJsonPath('periods.0.value', '1m')
        ->assertJsonPath('periods.1.label', '15 minutes')
        ->assertJsonMissing(['value' => 'BTC/USD:USD'])
        ->assertJsonMissing(['value' => '9m']);
});

it('does not return market metadata to visitors', function () {
    $this->get(route('markets.options', 'kraken'))->assertRedirect(route('login'));
});

it('interprets CCXT price precision without confusing it with minimum price or a candle period', function () {
    expect(MarketCatalog::tickSize(0.00000001, \ccxt\TICK_SIZE))->toBe('0.00000001')
        ->and(MarketCatalog::tickSize(2, \ccxt\DECIMAL_PLACES))->toBe('0.01')
        ->and(MarketCatalog::tickSize(8, \ccxt\SIGNIFICANT_DIGITS))->toBeNull();
});
