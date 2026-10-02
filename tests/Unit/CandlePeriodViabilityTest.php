<?php

use App\Domain\MarketData\CandlePeriodViability;

function viabilityCandle(string $open, string $close, ?string $high = null, ?string $low = null): array
{
    $high ??= (string) max((float) $open, (float) $close);
    $low ??= (string) min((float) $open, (float) $close);

    return ['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => '1'];
}

it('requires both BUY and SELL to reach the configured share of finalized auto labels', function () {
    $candles = [
        viabilityCandle('100', '100'),
        viabilityCandle('101', '100', '102', '99'),
        viabilityCandle('100', '102', '103', '99'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
    ];

    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.005, 0.01);

    expect($result['passes'])->toBeTrue()
        ->and($result['buy'])->toBe(1)
        ->and($result['sell'])->toBe(1)
        ->and($result['buy_ratio'])->toBeGreaterThanOrEqual(0.01)
        ->and($result['sell_ratio'])->toBeGreaterThanOrEqual(0.01);
});

it('rejects a period when transaction costs prune its BUY and SELL opportunities', function () {
    $candles = [
        viabilityCandle('100', '100'),
        viabilityCandle('101', '100', '102', '99'),
        viabilityCandle('100', '102', '103', '99'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
        viabilityCandle('102', '102'),
    ];

    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.02, 0.01);

    expect($result['passes'])->toBeFalse()
        ->and($result['buy'])->toBe(0)
        ->and($result['sell'])->toBe(0);
});
