<?php

use App\Repositories\TickerRepository;
use ccxt\Exchange;

it('calls normalize_ticker immediately on every raw CCXT OHLCV page', function () {
    $exchange = Mockery::mock(Exchange::class);
    $exchange->shouldReceive('fetch_ohlcv')->once()->with('BTC/USD', '1m', 1_700_000_000_000, 100, [])
        ->andReturn([[1_700_000_000_000, '1.000000009', '2', '1', '1.000000019', '12.34567890123456789']]);
    $exchange->shouldReceive('fetch_ohlcv')->once()->with('BTC/USD', '1m', 1_700_000_060_000, 100, [])
        ->andReturn([[1_700_000_060_000, '2', '3', '1', '2.5', '4']]);
    $repository = new class extends TickerRepository
    {
        public int $normalizations = 0;

        public function normalize_ticker(array &$tickers, bool $reindex = false, string $indexUnit = 'seconds'): array
        {
            $this->normalizations++;

            return parent::normalize_ticker($tickers, $reindex, $indexUnit);
        }
    };
    $repository->setExchange($exchange);

    $first = $repository->fetch('BTC/USD', '1m', 1_700_000_000_000, 100, []);
    $second = $repository->fetch('BTC/USD', '1m', 1_700_000_060_000, 100, []);

    expect($repository->normalizations)->toBe(2);
    expect(array_keys($first))->toBe([1_700_000_000]);
    expect(array_keys($second))->toBe([1_700_000_060]);
    expect($first[1_700_000_000]['close'])->toBe('1.000000019');
    expect($first[1_700_000_000]['volume'])->toBe('12.34567890123456789');
    expect($first[1_700_000_000]['microtimestamp'])->toBe(1_700_000_000_000);
    expect(array_key_exists(2, $first[1_700_000_000]))->toBeFalse();
});
