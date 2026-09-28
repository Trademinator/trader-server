<?php

use App\Traits\Technical;
use Tests\Support\TickerFixtures;

function sliceTechnical(): object
{
    return new class
    {
        use Technical;
    };
}

function calculateSliceIndicators(object $technical, array &$slice): void
{
    $technical->ema($slice, 24);
    $technical->ema($slice, 12);
    $technical->sma($slice, 20);
    $technical->smma($slice, 14);
    $technical->rsi($slice, 3);
    $technical->rsi($slice, 14);
    $technical->atrp($slice, 3);
    $technical->atrp($slice, 14);
    $technical->sto_rsi($slice, 14, 3, 3);
    $technical->cci($slice, 20);
    $technical->roc($slice, 4);
    $technical->roc($slice, 12);
    $technical->volume_activity($slice, 20);
    $technical->candle_geometry($slice);
}

it('matches the ordinary batch indicator results exactly across slice boundaries', function (int $batchSize, int $count) {
    $technical = sliceTechnical();
    $source = TickerFixtures::candles($count);
    $batch = $source;
    calculateSliceIndicators($technical, $batch);
    $largestSlice = 0;
    $callback = function (array &$slice) use ($technical, &$largestSlice): void {
        $largestSlice = max($largestSlice, count($slice));
        calculateSliceIndicators($technical, $slice);
    };

    $rows = iterator_to_array($technical->ticker_slide($source, $callback, 24, 3, $batchSize));

    expect($rows)->toBe($batch);
    expect(array_keys($rows))->toBe(array_keys($source));
    expect($largestSlice)->toBeLessThanOrEqual($batchSize + 24 * 5);
    foreach ($rows as $row) {
        expect(array_key_exists('__ticker_seed', $row))->toBeFalse();
        expect(array_key_exists('__ticker_cached', $row))->toBeFalse();
        expect(array_key_exists('__ticker_position', $row))->toBeFalse();
    }
})->with([
    'one candle' => [1, 240],
    'seven candles' => [7, 240],
    'two EMA24 periods' => [48, 360],
    'three EMA24 periods' => [72, 360],
    'database-sized batches' => [500, 1050],
]);

it('continues real EMA24 from the last calculated slice rather than reseeding raw prices', function () {
    $technical = sliceTechnical();
    $source = TickerFixtures::candles(161);
    $batch = $source;
    $key = $technical->ema($batch, 24);
    $prefix = array_slice($source, 0, 160, true);
    $technical->ema($prefix, 24);
    $slice = $technical->ticker_slice($prefix, 24, 2);
    $lastKey = array_key_last($source);
    $slice[$lastKey] = $source[$lastKey];

    $technical->ema($slice, 24);

    expect(count($slice))->toBe(49);
    expect($slice[$lastKey][$key])->toBe($batch[$lastKey][$key]);
});

it('refuses an unseeded raw tail for recursive indicators', function () {
    $technical = sliceTechnical();
    $slice = $technical->ticker_slice(TickerFixtures::candles(160), 24);

    expect(fn () => $technical->ema($slice, 24))->toThrow(LogicException::class);
});

it('refuses insufficient lookback instead of silently calculating a partial window', function () {
    $technical = sliceTechnical();
    $source = TickerFixtures::candles(160);
    $callback = function (array &$slice) use ($technical): void {
        $technical->sma($slice, 20);
    };

    expect(fn () => iterator_to_array($technical->ticker_slide($source, $callback, 2, 1, 40)))
        ->toThrow(LogicException::class);
});

it('keeps the full precision of a running sum before publishing its SMA', function () {
    $source = [['signal' => '1.000000019'], ['signal' => '1.000000021'], ['signal' => '1.000000019']];

    $key = sliceTechnical()->sma($source, 2, 'signal');

    expect(array_column($source, $key))->toBe(['1.00000001', '1.00000002', '1.00000002']);
});

it('keeps alternate ATR averaging modes separate on the same candle array', function () {
    $technical = sliceTechnical();
    $source = TickerFixtures::candles(80);
    $smaSource = $source;
    $sma = $technical->atrp($smaSource, 14, 'sma');
    $ema = $technical->atrp($source, 14, 'ema');
    $smma = $technical->atrp($source, 14);
    $smaAgain = $technical->atrp($source, 14, 'sma');

    expect(end($source)[$smaAgain])->toBe(end($smaSource)[$sma]);
    expect($ema)->not->toBe($smaAgain);
    expect($smma)->not->toBe($smaAgain);
});

it('preserves timestamp keys in position-dependent technical methods', function () {
    $technical = sliceTechnical();
    $indexed = TickerFixtures::candles(80);
    $list = array_values($indexed);
    $expected = $technical->ichimoku($list);
    $actual = $technical->ichimoku($indexed);
    $slope = $technical->slope($list, 'close', 2);
    $indexedSlope = $technical->slope($indexed, 'close', 2);

    expect($actual)->toBe($expected);
    expect($indexedSlope)->toBe($slope);
    expect(array_values($indexed))->toBe($list);
});

it('has no alternative single-candle indicator API', function () {
    $trait = new ReflectionClass(Technical::class);

    foreach ($trait->getMethods() as $method) {
        expect(str_ends_with($method->name, '_next'))->toBeFalse();
    }
});
