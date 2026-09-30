<?php

use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;

it('requires a history probe candle to fall inside the requested window', function (int $timestampOffset, bool $expected) {
    $from = 1_700_000_000_000;
    $until = $from + 60_000;
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->options = ['paginate' => false];
    $client->shouldReceive('fetch_ohlcv')->once()->with('BTC/USD', '1m', $from, 1, [])
        ->andReturn([[$from + $timestampOffset, 100, 102, 99, 101, 10]]);
    $tickers = new TickerRepository;
    $tickers->setExchange($client);
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'Test', 'class' => 'kraken']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickers);

    expect($repository->hasHistoricalData('BTC/USD', '1m', $from, $until, 1))->toBe($expected);
})->with([
    'inside' => [0, true],
    'clamped to upper bound' => [60_000, false],
]);
