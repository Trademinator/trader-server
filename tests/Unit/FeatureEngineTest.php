<?php

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Traits\Technical;

function m2Candles(int $count = 80, bool $flat = false): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $c = $flat ? 100.0 : 100 + $i;
        $rows[] = ['microtimestamp' => 1700000000000 + $i * 60000,
            'open' => (string) $c, 'high' => (string) ($flat ? $c : $c + 1),
            'low' => (string) ($flat ? $c : $c - 1), 'close' => (string) $c, 'volume' => $flat ? '0' : '10'];
    }

    return $rows;
}

it('has reproducible causal features and explicit warm up', function () {
    $engine = new FeatureEngine;
    $short = iterator_to_array($engine->rows(m2Candles(40), '1m', PHP_INT_MAX));
    $long = iterator_to_array($engine->rows(m2Candles(80), '1m', PHP_INT_MAX));
    expect(array_slice($long, 0, 40))->toBe($short)
        ->and($short[1]['indicators']['ema(3,close)'])->toBeNull()
        ->and($short[2]['indicators']['ema(3,close)'])->toBe('101.0000000000000000')
        ->and($short[3]['indicators']['rsi(3)'])->toBe('100.0000000000000000')
        ->and($short[26]['technical_ready'])->toBeFalse()
        ->and($short[27]['technical_ready'])->toBeTrue()
        ->and(array_keys($short[39]['features']))->toBe(FeatureEngine::KEYS);
});

it('keeps flat and zero volume candles finite and bounded', function () {
    $rows = iterator_to_array((new FeatureEngine)->rows(m2Candles(80, true), '1m', PHP_INT_MAX));
    $last = end($rows);
    expect($last['features']['momentum.rsi_14'])->toBe(0.5)
        ->and($last['features']['momentum.stoch_rsi_14'])->toBe(0.5)
        ->and($last['features']['momentum.cci_20'])->toBe(0.5)
        ->and($last['features']['volume.activity_20'])->toBe(0.5);
    foreach ($last['features'] as $value) {
        if ($value !== null) {
            expect(is_finite((float) $value))->toBeTrue()->and($value >= 0 && $value <= 1)->toBeTrue();
        }
    }
});

it('excludes active candles and resets after gaps', function () {
    $candles = m2Candles(60);
    unset($candles[40]);
    $rows = iterator_to_array((new FeatureEngine)->rows($candles, '1m', $candles[59]['microtimestamp']));
    expect(count($rows))->toBe(58)->and($rows[40]['technical_ready'])->toBeFalse()
        ->and($rows[40]['features']['momentum.rsi_14'])->toBeNull();
});

it('rejects duplicates unsorted timestamps and invalid prices', function () {
    $rows = m2Candles(2);
    $rows[1]['microtimestamp'] = $rows[0]['microtimestamp'];
    expect(fn () => iterator_to_array((new FeatureEngine)->rows($rows, '1m', PHP_INT_MAX)))->toThrow(InvalidArgumentException::class);
    $rows = m2Candles(2);
    $rows[1]['close'] = 'INF';
    expect(fn () => iterator_to_array((new FeatureEngine)->rows($rows, '1m', PHP_INT_MAX)))->toThrow(InvalidArgumentException::class);
});

it('uses elapsed time for daily returns', function () {
    $rows = iterator_to_array((new FeatureEngine)->rows(m2Candles(1441), '1m', PHP_INT_MAX));
    expect($rows[1439]['features']['return.24h'])->toBeNull()
        ->and($rows[1440]['indicators']['return(24h)'])->toBe('14.4000000000000000')
        ->and($rows[1440]['features']['return.7d'])->toBeNull();
});

it('never uses future or expired context and distinguishes missing from neutral', function () {
    $context = new ContextFeatures;
    $snapshot = ['snapshot_id' => 'example', 'observed_at_ms' => 1000, 'vs_currency' => 'usd', 'payload' => [
        'coin' => ['current_price' => 100, 'market_cap' => 1000, 'total_volume' => 0],
        'global' => ['market_cap_change_percentage_24h_usd' => 0, 'market_cap_percentage' => ['btc' => 50]],
    ]];
    expect($context->calculate($snapshot, 100, 999, 100)['snapshot_id'])->toBeNull()
        ->and($context->calculate($snapshot, 100, 1101, 100)['snapshot_id'])->toBeNull();
    $row = $context->calculate($snapshot, 100, 1000, 100);
    expect($row['features']['context.global_regime'])->toBe(0.5)
        ->and($row['features']['context.price_deviation'])->toBe(0.5)
        ->and($row['features']['context.activity'])->toBe(0.0)
        ->and($row['features']['context.circulating_fraction'])->toBeNull();
});

it('expires provider data independently of its receipt time and rejects malformed numeric context', function () {
    $snapshot = ['snapshot_id' => 'sample', 'observed_at_ms' => 1000, 'vs_currency' => 'usd', 'payload' => [
        'coin' => ['current_price' => 'invalid', 'total_volume' => -10, 'market_cap' => 100],
        'global' => ['market_cap_change_percentage_24h_usd' => 'invalid'], 'expires_at_ms' => 1050,
    ]];
    $context = new ContextFeatures;
    $row = $context->calculate($snapshot, 100, 1000, 1000);
    expect($row['features']['context.global_regime'])->toBeNull()
        ->and($row['features']['context.activity'])->toBeNull()
        ->and($row['features']['context.price_deviation'])->toBeNull()
        ->and($context->calculate($snapshot, 100, 1051, 1000)['snapshot_id'])->toBeNull();
});

it('uses Technical as the single source of truth for technical indicator math', function () {
    expect(class_uses(FeatureEngine::class))->toHaveKey(Technical::class);

    $source = m2Candles(80);
    $traitCandles = $source;
    $technical = new class
    {
        use Technical;
    };

    $ema3 = $technical->ema($traitCandles, 3, 'close');
    $ema12 = $technical->ema($traitCandles, 12, 'close');
    $rsi3 = $technical->rsi($traitCandles, 3);
    $rsi14 = $technical->rsi($traitCandles, 14);
    [$stochRsi14] = $technical->sto_rsi($traitCandles, 14, 3, 3);
    $cci20 = $technical->cci($traitCandles, 20);
    $atrp3 = $technical->atrp($traitCandles, 3);
    $atrp14 = $technical->atrp($traitCandles, 14);
    $trendDirection = $technical->compare($traitCandles, $ema3, $ema12);
    $candleGeometry = $technical->candle_geometry($traitCandles);
    $candleDirection = $candleGeometry[3];

    $rows = iterator_to_array((new FeatureEngine)->rows($source, '1m', PHP_INT_MAX));
    foreach ([27, 39, 79] as $i) {
        expect($rows[$i]['indicators']['ema(3,close)'])->toBe($traitCandles[$i][$ema3])
            ->and($rows[$i]['indicators']['ema(12,close)'])->toBe($traitCandles[$i][$ema12])
            ->and($rows[$i]['indicators']['rsi(3)'])->toBe($traitCandles[$i][$rsi3])
            ->and($rows[$i]['indicators']['rsi(14)'])->toBe($traitCandles[$i][$rsi14])
            ->and($rows[$i]['indicators']['stoch_rsi(14,14)'])->toBe($traitCandles[$i][$stochRsi14])
            ->and($rows[$i]['indicators']['cci(20)'])->toBe($traitCandles[$i][$cci20])
            ->and($rows[$i]['indicators']['atrp(3)'])->toBe($traitCandles[$i][$atrp3])
            ->and($rows[$i]['indicators']['atrp(14)'])->toBe($traitCandles[$i][$atrp14])
            ->and($rows[$i]['features']['trend.direction'])->toBe($traitCandles[$i][$trendDirection])
            ->and($rows[$i]['features']['candle.direction'])->toBe($traitCandles[$i][$candleDirection]);
    }
});

it('produces identical features for different batch sizes including gaps and a live last candle', function () {
    $source = \Tests\Support\TickerFixtures::candles(245);
    $keys = array_keys($source);
    foreach (array_slice($keys, 90, 5) as $key) {
        unset($source[$key]);
    }
    $cutoff = end($source)['microtimestamp'];
    $engine = new FeatureEngine;
    $expected = iterator_to_array($engine->rows($source, '1m', $cutoff, 1000));

    foreach ([1, 7, 64] as $batchSize) {
        $actual = iterator_to_array($engine->rows($source, '1m', $cutoff, $batchSize));
        expect($actual)->toBe($expected);
    }
    expect($expected)->toHaveCount(239);
    expect($expected[90]['technical_ready'])->toBeFalse();
    expect($expected[90]['indicators']['rsi(14)'])->toBeNull();
    expect($expected[117]['technical_ready'])->toBeTrue();
    expect(end($expected)['microtimestamp'])->toBeLessThan($cutoff);
});

it('ignores incoming indicator cache fields instead of trusting another history or feature version', function () {
    $source = \Tests\Support\TickerFixtures::candles(65);
    $engine = new FeatureEngine;
    $expected = iterator_to_array($engine->rows($source, '1m', PHP_INT_MAX));
    foreach ($source as &$row) {
        $row['ema(3,close)'] = '999999999';
        $row['__ticker_seed'] = true;
        $row['__ticker_cached'] = true;
        $row['__ticker_position'] = 9000;
    }
    unset($row);

    expect(iterator_to_array($engine->rows($source, '1m', PHP_INT_MAX, 7)))->toBe($expected);
});
