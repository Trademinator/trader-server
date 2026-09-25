<?php

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;

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
        ->and($short[2]['indicators']['ema(3,close)'])->toBe(101.0)
        ->and($short[3]['indicators']['rsi(3)'])->toBe(100.0)
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
        ->and($rows[1440]['indicators']['return(24h)'])->toBe(14.4)
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
