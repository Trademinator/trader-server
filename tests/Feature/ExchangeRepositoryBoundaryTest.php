<?php

use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\NetworkError;
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
        ->with('BTC/USD', '1m', $fromMs, 100, [])
        ->andReturn($first);
    $tickerRepository->shouldReceive('fetch')
        ->once()
        ->with('BTC/USD', '1m', $toMs, 100, [])
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

it('passes the requested candle limit to CCXT for one bounded page', function () {
    $from = 1_700_000_000;
    $tickerRepository = Mockery::mock(TickerRepository::class);
    $tickerRepository->shouldReceive('fetch')->once()
        ->with('BTC/USD', '1m', $from * 1000, 10, [])
        ->andReturn([['microtimestamp' => $from * 1000, 'open' => '1', 'high' => '2', 'low' => '1', 'close' => '2', 'volume' => '1']]);
    $tickerRepository->shouldReceive('saveTickers')->once()->andReturn(1);

    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'Test', 'class' => 'kraken']));
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickerRepository);

    expect($repository->fetch('BTC/USD', '1m', $from, $from, 10))->toHaveCount(1);
});

it('uses bounded Coinbase until windows and continues past an empty batch', function () {
    $from = 1_700_000_000;
    $start = $from * 1000;
    $next = $start + 100 * 60_000;
    $tickerRepository = Mockery::mock(TickerRepository::class);
    $tickerRepository->shouldReceive('fetch')->once()
        ->with('BTC/USD', '1m', $start, 100, ['until' => $next])->andReturn([]);
    $tickerRepository->shouldReceive('fetch')->once()
        ->with('BTC/USD', '1m', $next, 100, ['until' => $next + 100 * 60_000])
        ->andReturn([['microtimestamp' => $next, 'open' => '1', 'high' => '2', 'low' => '1', 'close' => '2', 'volume' => '1']]);
    $tickerRepository->shouldReceive('saveTickers')->once()->andReturn(1);

    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'Test', 'class' => 'coinbase']));
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickerRepository);

    $result = $repository->fetch('BTC/USD', '1m', $from, $from + 100 * 60);

    expect($result)->toHaveCount(1)->and($result[0]['microtimestamp'])->toBe($next);
});

it('fetches one normalized history page with adapter bounds without saving before the checkpoint transaction', function (string $exchangeClass, array $params) {
    $from = 1_700_000_000_000;
    $until = $from + 90 * 60000;
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->options = ['paginate' => true, 'fetchOHLCV' => ['paginate' => true]];
    $client->shouldReceive('fetch_ohlcv')->once()->with('BTC/USD', '1m', $from, 90, $params)
        ->andReturn([[$from, 100, 102, 99, 101, 10]]);
    $tickers = new TickerRepository;
    $tickers->setExchange($client);
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'Test', 'class' => $exchangeClass]));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickers);

    $candles = $repository->fetchHistoryPage('BTC/USD', '1m', $from, $until, 90);

    expect(array_values($candles)[0])->toMatchArray(['microtimestamp' => $from, 'close' => '101']);
    expect($client->options['paginate'])->toBeFalse();
    expect($client->options['fetchOHLCV']['paginate'])->toBeFalse();
    $this->assertDatabaseCount('tickers', 0);
})->with([
    'generic' => ['kraken', []],
    'Bitso end in milliseconds' => ['bitso', ['end' => 1_700_005_400_000]],
    'Coinbase until in milliseconds' => ['coinbase', ['until' => 1_700_005_400_000]],
    'NDAX explicit ToDate' => ['ndax', ['ToDate' => '2023-11-14 23:43:20']],
]);

it('bounds the CCXT history limit to the requested backfill window', function () {
    $from = 1_700_000_000_000;
    $until = $from + 24 * 60 * 60 * 1000;
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->options = ['paginate' => true, 'fetchOHLCV' => ['paginate' => true]];
    $client->shouldReceive('fetch_ohlcv')->once()
        ->with('BTC/USDC', '1h', $from, 24, ['ToDate' => ccxt\Exchange::ymdhms($until)])
        ->andReturn([[$from, 100, 102, 99, 101, 10]]);

    $tickers = new TickerRepository;
    $tickers->setExchange($client);
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['name' => 'NDAX', 'class' => 'ndax']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);
    $reflection->getProperty('tickerRepository')->setValue($repository, $tickers);

    $candles = $repository->fetchHistoryPage('BTC/USDC', '1h', $from, $until, 90);

    $candle = array_values($candles)[0];
    expect($candles)->toHaveCount(1)
        ->and($candle['microtimestamp'])->toBe($from);
    expect($client->options['paginate'])->toBeFalse();
    expect($client->options['fetchOHLCV']['paginate'])->toBeFalse();
    $this->assertDatabaseCount('tickers', 0);
});

it('reads generic reconstruction evidence as decimal strings with bounded adapter parameters', function (string $exchangeClass, array $params) {
    $from = 1704069000000;
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->timeframes = ['5m' => '5m'];
    $client->number = 'floatval';
    $client->options = ['paginate' => true, 'fetchOHLCV' => ['paginate' => true]];
    $client->shouldReceive('fetch_ohlcv')->once()->with('ATOM/USD', '5m', $from, 3, $params)
        ->andReturnUsing(function () use ($client, $from) {
            return [[$from, $client->parse_number('11.123456789123456789'), '12', '10', '11.5',
                $client->parse_number('0.000000000000000001')]];
        });
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => $exchangeClass]));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);

    $rows = $repository->fetchCandleEvidence('ATOM/USD', '5m', $from, $from + 900000, 100);

    expect($rows[0])->toMatchArray(['open' => '11.123456789123456789', 'volume' => '0.000000000000000001']);
    expect($client->number)->toBe('floatval');
    expect($client->options['fetchOHLCV']['paginate'])->toBeFalse();
    $this->assertDatabaseCount('tickers', 0);
})->with([
    'generic' => ['kraken', []],
    'Coinbase until' => ['coinbase', ['until' => 1704069900000]],
    'NDAX ToDate' => ['ndax', ['ToDate' => '2024-01-01 00:45:00']],
]);

it('restores CCXT numeric mode after a failed reconstruction evidence request', function () {
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->timeframes = ['5m' => '5m'];
    $client->number = 'floatval';
    $client->shouldReceive('fetch_ohlcv')->once()->andThrow(new NetworkError('offline'));
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => 'kraken']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);

    expect(fn () => $repository->fetchCandleEvidence('ATOM/USD', '5m', 1704069000000, 1704069900000, 3))
        ->toThrow(NetworkError::class);

    expect($client->number)->toBe('floatval');
    $this->assertDatabaseCount('tickers', 0);
});

it('does not normalize unknown evidence volume into zero', function () {
    $client = Mockery::mock(ccxt\Exchange::class)->makePartial();
    $client->timeframes = ['5m' => '5m'];
    $client->shouldReceive('fetch_ohlcv')->once()->andReturn([[1704069000000, '11', '12', '10', '11.5', null]]);
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => 'kraken']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);

    expect(fn () => $repository->fetchCandleEvidence('ATOM/USD', '5m', 1704069000000, 1704069900000, 3))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('tickers', 0);
});
