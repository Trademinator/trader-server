<?php

use App\Domain\MarketData\OhlcvNormalizer;

it('normalizes raw CCXT candles into one canonical associative shape', function () {
    $raw = [
        [1_700_000_000_000, 100, 103.5, 99.25, 102.125, 12.5],
        [1_700_000_060_000, '102.125', '104', '101', '103.75', null],
    ];

    $candles = (new OhlcvNormalizer)->normalize($raw);

    expect($candles)->toHaveCount(2)
        ->and($candles[0]['microtimestamp'])->toBe(1_700_000_000_000)
        ->and($candles[0]['open'])->toBe('100.00000000')
        ->and($candles[0]['close'])->toBe('102.12500000')
        ->and($candles[1]['volume'])->toBe('0.00000000')
        ->and(array_key_exists(0, $candles[0]))->toBeFalse()
        ->and(array_key_exists(5, $candles[0]))->toBeFalse();
});

it('reindexes canonical candles by microtimestamp without changing the candle shape', function () {
    $raw = [
        [1_700_000_000_000, 100, 101, 99, 100.5, 5],
        [1_700_000_060_000, 100.5, 102, 100, 101.5, 6],
    ];

    $candles = (new OhlcvNormalizer)->normalize($raw, true);

    expect(array_keys($candles))->toBe([1_700_000_000_000, 1_700_000_060_000])
        ->and($candles[1_700_000_060_000]['close'])->toBe('101.50000000');
});

it('preserves computed associative indicator keys when renormalizing', function () {
    $input = [[
        'microtimestamp' => 1_700_000_000_000,
        'open' => '100',
        'high' => '102',
        'low' => '99',
        'close' => '101',
        'volume' => '7',
        'ema(3,close)' => '100.75',
    ]];

    $candles = (new OhlcvNormalizer)->normalize($input);

    expect($candles[0]['ema(3,close)'])->toBe('100.75');
});

it('rejects malformed candles early', function () {
    expect(fn () => (new OhlcvNormalizer)->normalize([[1_700_000_000_000, 'bad', 2, 1, 2, 1]]))
        ->toThrow(InvalidArgumentException::class);
});
