<?php

use Trademinator\Indicators\Traits\TickerManipulation;

use function Trademinator\BcMath\bcconv;

function tickerManipulation(): object
{
    return new class
    {
        use TickerManipulation;
    };
}

it('uses Unix seconds for reindexed candles and keeps the original milliseconds', function () {
    $tickers = [
        [1_790_620_264_123, '100', '103', '99', '102.123456789123456789', '12.34567890123456789'],
        [1_790_620_324_123, '102', '104', '101', '103', '4'],
    ];

    $result = tickerManipulation()->normalize_ticker($tickers, true);

    expect(array_keys($result))->toBe([1_790_620_264, 1_790_620_324]);
    expect($result)->toBe($tickers);
    expect($tickers[1_790_620_264]['microtimestamp'])->toBe(1_790_620_264_123);
    expect($tickers[1_790_620_264]['close'])->toBe('102.123456789123456789');
    expect($tickers[1_790_620_264]['volume'])->toBe('12.34567890123456789');
    expect(array_key_exists(2, $tickers[1_790_620_264]))->toBeFalse();
    $tickers[1_790_620_264]['close'] = '100';
    expect($tickers[1_790_620_324]['close'])->toBe('103');
});

it('retains distinct sub-second timestamps in explicit millisecond indexing mode', function () {
    $raw = [[123, '1', '1', '1', '1', '0'], [456, '2', '2', '2', '2', '0']];

    $result = tickerManipulation()->normalize_ticker($raw, true, 'milliseconds');

    expect(array_keys($result))->toBe([123, 456]);
});

it('rejects colliding second keys without partially mutating the input', function () {
    $raw = [[123, '1', '1', '1', '1', '0'], [456, '2', '2', '2', '2', '0']];
    $original = $raw;
    $normalizer = tickerManipulation();

    expect(function () use ($normalizer, &$raw): void {
        $normalizer->normalize_ticker($raw, true);
    })->toThrow(InvalidArgumentException::class);
    expect($raw)->toBe($original);
});

it('is idempotent for named candles and preserves existing keys without reindexing', function () {
    $raw = [71 => [0, '1.000000009', '2', '1', '1.000000019', null]];
    $normalizer = tickerManipulation();
    $normalizer->normalize_ticker($raw);
    $raw[71]['ema(24,close)'] = '1.1234567891234567';
    $expected = $raw;

    $normalizer->normalize_ticker($raw);

    expect($raw)->toBe($expected);
    expect($raw[71]['volume'])->toBe('0');
    expect($raw[71]['human_date'])->toBe('19700101000000');
});

it('expands scientific notation without narrowing decimal strings through float', function () {
    $normalizer = tickerManipulation();
    $oldPrecision = ini_get('precision');
    $oldSerializePrecision = ini_get('serialize_precision');
    try {
        ini_set('precision', '5');
        ini_set('serialize_precision', '5');
        expect(bcconv('1.23456789123456789e-7'))->toBe('0.000000123456789123456789');
        expect(bcconv(1.25e-12))->toBe('0.00000000000125');
        expect(ini_get('serialize_precision'))->toBe('5');
    } finally {
        ini_set('precision', $oldPrecision);
        ini_set('serialize_precision', $oldSerializePrecision);
    }
});

it('validates high and low using the complete incoming decimal precision', function () {
    $raw = [[0, '1.000000000000000002', '1.000000000000000001', '1', '1', '0']];

    expect(fn () => tickerManipulation()->normalize_ticker($raw))->toThrow(InvalidArgumentException::class);
});

it('validates ticker slice arguments and does not call the calculator for empty input', function () {
    $trait = tickerManipulation();
    $called = false;
    $calculate = function (array &$slice) use (&$called): void {
        $called = true;
    };

    expect(iterator_to_array($trait->ticker_slide([], $calculate, 24)))->toBe([]);
    expect($called)->toBeFalse();
    expect(fn () => $trait->ticker_slice([], 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $trait->ticker_slice([], 24, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => iterator_to_array($trait->ticker_slide([], $calculate, 24, batchSize: 0)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects calculators that renumber timestamp keys', function () {
    $trait = tickerManipulation();
    $rows = [100 => ['close' => '1'], 200 => ['close' => '2']];
    $calculate = static function (array &$slice): void {
        $slice = array_values($slice);
    };

    expect(fn () => iterator_to_array($trait->ticker_slide($rows, $calculate, 2)))->toThrow(LogicException::class);
});
