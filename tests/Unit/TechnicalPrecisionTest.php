<?php

use App\Traits\Technical;

function f10Technical(): object
{
    return new class
    {
        use Technical;
    };
}

function f10PrecisionCandles(int $count = 100): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $unit = '0.00000000000000000001';
        $close = bcmul((string) (100 + ($i % 11)), $unit, 24);
        // Occasionally introduce more decimal places; padding is not precision.
        if ($i % 17 === 0) {
            $close = bcadd($close, '0.000000000000000000000123', 24);
        }
        $rows[1_700_000_000 + $i * 60] = [
            'microtimestamp' => 1_700_000_000_000 + $i * 60_000,
            'open' => $close,
            'high' => bcadd($close, $unit, 24),
            'low' => bcsub($close, $unit, 24),
            'close' => $close,
            'volume' => '1.00000000',
        ];
    }

    return $rows;
}

function f10Calculate(object $technical, array &$rows): void
{
    $technical->sma($rows, 3);
    $technical->ema($rows, 3);
    $technical->smma($rows, 3);
    $technical->cci($rows, 3);
    foreach (['sma', 'ema', 'smma'] as $average) {
        $technical->atrp($rows, 3, $average);
    }
}

it('counts decimal strings exactly and optionally ignores only fractional trailing zeroes', function () {
    $technical = f10Technical();

    expect($technical->bcdec())->toBe(2)
        ->and($technical->bcdec('100'))->toBe(2)
        ->and($technical->bcdec('1.230000'))->toBe(6)
        ->and($technical->bcdec('1.230000', trimTrailingZeros: true))->toBe(2)
        ->and($technical->bcdec('0.000001230000', trimTrailingZeros: true))->toBe(8)
        ->and($technical->bcdec('-1.2000', '0.0000456000', trimTrailingZeros: true))->toBe(7)
        ->and($technical->bcdec('1.2300e-10'))->toBe(14)
        ->and($technical->bcdec('1.2300e-10', trimTrailingZeros: true))->toBe(12)
        ->and($technical->bcdec('123456789012345678901234567890.000000000000000000123000', trimTrailingZeros: true))->toBe(21)
        ->and($technical->bcdec('0.000000000000000000000000', trimTrailingZeros: true))->toBe(2);
});

it('rejects an invalid trailing-zero option', function () {
    expect(fn () => f10Technical()->bcdec('1.2', trimTrailingZeros: 'yes'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses the retained exchange constant as a floor and caps calculation scale at 32', function () {
    $technical = f10Technical();

    expect(defined('EXCHANGE_ROUND_DECIMALS'))->toBeTrue()
        ->and($technical->technical_scale('1.23000000000000000000'))
        ->toBe(min(32, max(EXCHANGE_ROUND_DECIMALS * 2, 6)))
        ->and($technical->technical_scale('0.00000000000000000001'))
        ->toBe(min(32, max(EXCHANGE_ROUND_DECIMALS * 2, 24)))
        ->and($technical->technical_scale('0.'.str_repeat('0', 39).'1'))->toBe(32);
});

it('retains the F10 tiny true range instead of truncating it to zero', function () {
    $technical = f10Technical();
    $range = $technical->technical_true_range_value('0.0000000123', '0.0000000100');

    expect(bccomp($range, '0.0000000023', 32))->toBe(0)
        ->and($range)->toBe('0.0000000023000000');
});

it('compares all true-range candidates at the adaptive scale including tiny gaps', function () {
    $technical = f10Technical();
    $range = $technical->technical_true_range_value('1.2e-19', '1.0e-19', '5e-20');

    expect(bccomp($range, '0.00000000000000000007', 32))->toBe(0);
});

it('retains tiny typical prices and accepts exact scientific-notation strings', function () {
    $technical = f10Technical();
    $price = $technical->technical_typical_price_value('1.23e-8', '1.00e-8', '1.13e-8');

    expect(bccomp($price, '0.0000000112', 32))->toBe(0);
});

it('keeps integer digits that would be lost by a float conversion', function () {
    $technical = f10Technical();
    $price = $technical->technical_typical_price_value(
        '123456789012345678901234567890.0000000123',
        '123456789012345678901234567890.0000000100',
        '123456789012345678901234567890.0000000113'
    );

    expect(bccomp($price, '123456789012345678901234567890.0000000112', 32))->toBe(0);
});

it('keeps tiny SMA values and the exact rolling sum', function () {
    $technical = f10Technical();
    $rows = array_map(static fn (string $value): array => ['signal' => $value], [
        '0.0000000123', '0.0000000100', '0.0000000113',
    ]);
    $key = $technical->sma($rows, 3, 'signal');

    expect(array_column($rows, $key))->toBe([
        '0.0000000123000000', '0.0000000111500000', '0.0000000112000000',
    ]);
});

it('does not keep unnecessary precision after a value leaves the SMA window', function () {
    $technical = f10Technical();
    $rows = array_map(static fn (string $value): array => ['signal' => $value], [
        '1.00000000000000000001', '-1.00000000000000000001', '1', '2', '3',
    ]);
    $key = $technical->sma($rows, 2, 'signal');

    expect($rows[1][$key])->toBe('0.0000000000000000')
        ->and($rows[4][$key])->toBe('2.5000000000000000');
});

it('keeps ATR and ATRP nonzero for every supported average on tiny prices', function (string $average, string $unit) {
    $technical = f10Technical();
    $close = bcmul('10', $unit, 32);
    $rows = array_fill(0, 8, [
        'open' => $close, 'close' => $close,
        'high' => bcmul('12', $unit, 32),
        'low' => bcmul('8', $unit, 32), 'volume' => '1',
    ]);
    $atr = $technical->atr($rows, 3, $average);
    $atrp = $technical->atrp($rows, 3, $average);
    $last = end($rows);

    expect(bccomp($last[$atr], bcmul('4', $unit, 32), 32))->toBe(0)
        ->and(bccomp($last[$atrp], '40', 12))->toBe(0);
})->with([
    'SMA 1e-10' => ['sma', '0.0000000001'],
    'EMA 1e-10' => ['ema', '0.0000000001'],
    'SMMA 1e-10' => ['smma', '0.0000000001'],
    'SMA 1e-20' => ['sma', '0.00000000000000000001'],
    'EMA 1e-20' => ['ema', '0.00000000000000000001'],
    'SMMA 1e-20' => ['smma', '0.00000000000000000001'],
    'SMA 1e-28' => ['sma', '0.0000000000000000000000000001'],
    'EMA 1e-28' => ['ema', '0.0000000000000000000000000001'],
    'SMMA 1e-28' => ['smma', '0.0000000000000000000000000001'],
]);

it('keeps tiny-price CCI close to the unscaled mathematical result', function () {
    $technical = f10Technical();
    $rows = [];
    $unit = '0.00000000000000000001';
    foreach (['10', '11', '12'] as $price) {
        $close = bcmul($price, $unit, 24);
        $rows[] = [
            'open' => $close, 'close' => $close,
            'high' => bcadd($close, $unit, 24),
            'low' => bcsub($close, $unit, 24), 'volume' => '1',
        ];
    }
    $key = $technical->cci($rows, 3);
    $error = $technical->bcabs(bcsub(end($rows)[$key], '100', 32));

    // Four input guard digits leave the repeating mean deviation finite.
    // The CCI must remain near 100, not collapse to zero; this bound allows
    // the ratio's amplification of that documented truncation.
    expect(bccomp($error, '0.02', 32))->toBe(-1);
});

it('preserves zero-range and zero-deviation behavior', function () {
    $technical = f10Technical();
    $rows = array_fill(0, 8, [
        'open' => '0', 'high' => '0', 'low' => '0', 'close' => '0', 'volume' => '0',
    ]);
    $cci = $technical->cci($rows, 3);
    $atrp = $technical->atrp($rows, 3);

    expect(bccomp(end($rows)[$cci], '0', 32))->toBe(0)
        ->and(bccomp(end($rows)[$atrp], '0', 32))->toBe(0);
});

it('does not alter raw OHLCV or select an earlier scale using future candles', function () {
    $technical = f10Technical();
    $source = f10PrecisionCandles();
    $all = $source;
    $prefix = array_slice($source, 0, 31, true);
    f10Calculate($technical, $all);
    f10Calculate($technical, $prefix);

    expect(array_slice($all, 0, 31, true))->toBe($prefix);
    foreach ($source as $key => $row) {
        expect(array_intersect_key($all[$key], $row))->toBe($row);
    }
});

it('matches batch results exactly when tiny prices cross retained slice boundaries', function (int $batchSize) {
    $technical = f10Technical();
    $source = f10PrecisionCandles();
    $batch = $source;
    f10Calculate($technical, $batch);
    $calculate = static function (array &$slice) use ($technical): void {
        f10Calculate($technical, $slice);
    };
    $sliced = iterator_to_array($technical->ticker_slide($source, $calculate, 3, 3, $batchSize));

    expect($sliced)->toBe($batch);
})->with([1, 7, 23, 500]);


it('does not inflate ordinary recursive scales merely by adding another candle', function () {
    $technical = f10Technical();
    $rows = [];
    for ($i = 0; $i < 80; $i++) {
        $rows[] = ['close' => (string) (100 + ($i % 13))];
    }
    $ema = $technical->ema($rows, 4);
    $smma = $technical->smma($rows, 4);

    foreach ($rows as $row) {
        expect($technical->bcdec($row[$ema]))->toBe(16)
            ->and($technical->bcdec($row[$smma]))->toBe(16);
    }
});

it('retains truncation toward zero rather than introducing rounding', function () {
    $technical = f10Technical();
    $rows = [['signal' => '-1'], ['signal' => '-1'], ['signal' => '0']];
    $key = $technical->sma($rows, 3, 'signal');

    expect(end($rows)[$key])->toBe('-0.6666666666666666');
});

it('does not change the process-wide BCMath scale', function () {
    $old = bcscale(3);
    try {
        $technical = f10Technical();
        $rows = f10PrecisionCandles(8);
        f10Calculate($technical, $rows);

        expect(bcscale())->toBe(3);
    } finally {
        bcscale($old);
    }
});

it('versions the changed feature calculations separately from m2-v3', function () {
    expect(\App\Domain\Features\FeatureEngine::VERSION)->toBe('m2-v4');
});
