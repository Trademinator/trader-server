<?php

use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Mockery\MockInterface;

it('fetches a candle exactly on the requested end boundary', function () {
    $from = 1_700_000_000;
    $to = $from + 60;
    $fromMs = $from * 1000;
    $toMs = $to * 1000;

    $first = [[
        'microtimestamp' => $fromMs,
        'open' => '100.00000000',
        'high' => '101.00000000',
        'low' => '99.00000000',
        'close' => '100.50000000',
        'volume' => '1.00000000',
    ]];
    $second = [[
        'microtimestamp' => $toMs,
        'open' => '100.50000000',
        'high' => '102.00000000',
        'low' => '100.00000000',
        'close' => '101.50000000',
        'volume' => '2.00000000',
    ]];

    /** @var TickerRepository&MockInterface $tickerRepository */
    $tickerRepository = Mockery::mock(TickerRepository::class);
    $tickerRepository->shouldReceive('fetch')
        ->once()
        ->with('BTC/USD', '1m', $fromMs, 1000, [])
        ->andReturn($first);
    $tickerRepository->shouldReceive('fetch')
        ->once()
        ->with('BTC/USD', '1m', $toMs, 1000, [])
        ->andReturn($second);
    $tickerRepository->shouldReceive('saveTickers')
        ->once()
        ->andReturn(2);

    $repository = new ExchangeRepository;
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);

    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, $exchange);
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickerRepository);

    $candles = $repository->fetch('BTC/USD', '1m', $from, $to);

    expect($candles)->toHaveCount(2)
        ->and($candles[0]['microtimestamp'])->toBe($fromMs)
        ->and($candles[1]['microtimestamp'])->toBe($toMs);
});

it('rejects an inverted fetch range', function () {
    $repository = new ExchangeRepository;

    expect(fn () => $repository->fetch('BTC/USD', '1m', 200, 100))
        ->toThrow(InvalidArgumentException::class);
});

it('continues past an empty Coinbase batch and finds later candles', function () {
    $from = 1_700_000_000;
    $start = $from * 1000;
    $next = $start + 250 * 60_000;
    $tickerRepository = Mockery::mock(TickerRepository::class);
    $tickerRepository->shouldReceive('fetch')->once()
        ->with('BTC/USD', '1m', $start, 250, ['end' => $next])->andReturn([]);
    $tickerRepository->shouldReceive('fetch')->once()
        ->with('BTC/USD', '1m', $next, 250, ['end' => $next])
        ->andReturn([['microtimestamp' => $next, 'open' => '1', 'high' => '2', 'low' => '1', 'close' => '2', 'volume' => '1']]);
    $tickerRepository->shouldReceive('saveTickers')->once()->andReturn(1);

    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'Test', 'class' => 'coinbase']));
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickerRepository);

    $result = $repository->fetch('BTC/USD', '1m', $from, $from + 250 * 60);

    expect($result)->toHaveCount(1)->and($result[0]['microtimestamp'])->toBe($next);
});
