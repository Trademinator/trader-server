<?php

use App\Traits\Patterns;
use App\Traits\Technical;

function m0IndicatorCandles(int $count = 80): array
{
    $candles = [];

    for ($i = 0; $i < $count; $i++) {
        $base = 100 + ($i * 0.35) + (sin($i / 3) * 2.5);
        $open = $base + (($i % 3) - 1) * 0.25;
        $close = $base + (($i % 4) - 1.5) * 0.3;
        $high = max($open, $close) + 1.2 + (($i % 5) * 0.05);
        $low = min($open, $close) - 1.1 - (($i % 7) * 0.04);

        $candles[] = [
            'microtimestamp' => 1_700_000_000_000 + ($i * 60_000),
            'open' => number_format($open, 8, '.', ''),
            'high' => number_format($high, 8, '.', ''),
            'low' => number_format($low, 8, '.', ''),
            'close' => number_format($close, 8, '.', ''),
            'volume' => number_format(10 + ($i % 11), 8, '.', ''),
        ];
    }

    return $candles;
}

function m0Technical(): object
{
    return new class
    {
        use Technical;
    };
}

it('computes CCI with mean absolute deviation and protects a zero denominator', function () {
    $candles = array_fill(0, 30, [
        'microtimestamp' => 1_700_000_000_000,
        'open' => '100.00000000',
        'high' => '100.00000000',
        'low' => '100.00000000',
        'close' => '100.00000000',
        'volume' => '1.00000000',
    ]);

    foreach ($candles as $i => &$candle) {
        $candle['microtimestamp'] += $i * 60_000;
    }
    unset($candle);

    $key = m0Technical()->cci($candles, 20);

    expect($key)->toBe('cci(20)')
        ->and((float) end($candles)[$key])->toBe(0.0);
});

it('uses the requested ATR averaging function instead of always falling through to SMA', function () {
    $source = m0IndicatorCandles();
    $technical = m0Technical();

    $expectedSma = $source;
    $trKey = $technical->tr($expectedSma);
    $smaKey = $technical->sma($expectedSma, 5, $trKey);
    $expectedSmaValue = end($expectedSma)[$smaKey];

    $actualSma = $source;
    $atrSmaKey = $technical->atr($actualSma, 5, 'sma');
    expect(end($actualSma)[$atrSmaKey])->toBe($expectedSmaValue);

    $expectedEma = $source;
    $trKey = $technical->tr($expectedEma);
    $emaKey = $technical->ema($expectedEma, 5, $trKey);
    $expectedEmaValue = end($expectedEma)[$emaKey];

    $actualEma = $source;
    $atrEmaKey = $technical->atr($actualEma, 5, 'ema');
    expect(end($actualEma)[$atrEmaKey])->toBe($expectedEmaValue)
        ->and($expectedEmaValue)->not->toBe($expectedSmaValue);
});

it('honors the requested stochastic and stochastic RSI periods', function () {
    $technical = m0Technical();

    $priceCandles = m0IndicatorCandles();
    [$fastK] = $technical->sto($priceCandles, 5, 3, 3);
    $lastPrice = end($priceCandles);

    expect($fastK)->toBe('%k_price(5)')
        ->and($lastPrice)->toHaveKey('max(5,high,8)')
        ->and($lastPrice)->toHaveKey('min(5,low,8)');

    $rsiCandles = m0IndicatorCandles();
    [$rsiFastK] = $technical->sto_rsi($rsiCandles, 5, 3, 3);
    $lastRsi = end($rsiCandles);

    expect($rsiFastK)->toBe('%k(5)')
        ->and($lastRsi)->toHaveKey('max(5,rsi(5),8)')
        ->and($lastRsi)->toHaveKey('min(5,rsi(5),8)');
});

it('executes indicators that previously used invalid bare trait-method calls', function () {
    $technical = m0Technical();

    $macdCandles = m0IndicatorCandles();
    $macdKeys = $technical->macd($macdCandles, 6, 13, 4);
    expect(end($macdCandles))->toHaveKeys($macdKeys);

    $aoCandles = m0IndicatorCandles();
    [$aoKey] = $technical->ao($aoCandles, 5, 20);
    expect(end($aoCandles))->toHaveKey($aoKey);

    $acCandles = m0IndicatorCandles();
    $acKey = $technical->ac($acCandles, 5);
    expect(end($acCandles))->toHaveKey($acKey);

    $adxCandles = m0IndicatorCandles();
    $adxKeys = $technical->adx($adxCandles, 5);
    expect(end($adxCandles))->toHaveKeys($adxKeys);

    $chopCandles = m0IndicatorCandles();
    $chopKey = $technical->chop($chopCandles, 14);
    expect(end($chopCandles))->toHaveKey($chopKey);
});

it('quarantines unfinished candlestick-pattern routines instead of executing partial code', function () {
    $patterns = new class
    {
        use Patterns;
    };
    $candles = m0IndicatorCandles();

    expect(fn () => $patterns->is_dragonfly($candles))->toThrow(LogicException::class)
        ->and(fn () => $patterns->is_grave_stone($candles))->toThrow(LogicException::class);
});
