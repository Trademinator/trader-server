<?php

use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('uses the existing selector and ignores the unfinished last candle', function () {
    $to = strtotime('2020-01-02 00:00:00 UTC');
    $flat = array_map(fn (int $n): array => [
        'microtimestamp' => ($to - (50 - $n) * 60) * 1000,
        'open' => '1', 'high' => '1', 'low' => '1', 'close' => '1', 'volume' => '0',
    ], range(0, 49));
    $moving = array_map(fn (int $n): array => [
        'microtimestamp' => ($to - (51 - $n) * 300) * 1000,
        'open' => (string) $n, 'high' => (string) ($n + 2),
        'low' => (string) ($n - 1), 'close' => (string) ($n + 1), 'volume' => '1',
    ], range(1, 50));
    $moving[] = ['microtimestamp' => $to * 1000, 'open' => '1', 'high' => '1', 'low' => '1', 'close' => '1', 'volume' => '0'];

    $exchange = new Exchange(['name' => 'Demo', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->once()->with($exchange, [], null, 'BTC/USD');
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('periods')->once()->andReturn(['1m' => '1m', '5m' => '5m']);
    $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', Mockery::any(), $to)->andReturn($flat);
    $repository->shouldReceive('fetch')->once()->with('BTC/USD', '5m', Mockery::any(), $to)->andReturn($moving);
    app()->instance(ExchangeRepository::class, $repository);

    $exit = Artisan::call('trademinator:select-candle-period', [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', '--periods' => '1m,5m',
        '--tick-size' => '0.01', '--minimum' => '50',
        '--from' => '2020-01-01 00:00:00 UTC', '--to' => '2020-01-02 00:00:00 UTC',
    ]);

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('"period": "5m"')
        ->and(DB::table('candle_period_selections')->where('exchange', 'kraken')->where('period', '5m')->count())->toBe(1);
});

it('bounds a multi-year selection request to the recent quality sample', function () {
    $to = strtotime('2020-01-02 00:00:00 UTC');
    $exchange = new Exchange(['name' => 'Demo', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('findByClass')->once()->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('periods')->once()->andReturn(['1m' => '1m']);
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', $to - 251 * 60, $to)->andReturn([]);
    app()->instance(ExchangeRepository::class, $repository);

    $this->artisan('trademinator:select-candle-period', ['exchange' => 'kraken', 'symbol' => 'BTC/USD',
        '--periods' => '1m', '--tick-size' => '0.01', '--from' => '2010-01-01', '--to' => '2020-01-02 00:00:00 UTC'])
        ->assertFailed();
});
