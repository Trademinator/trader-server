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
        ->and($result['hold'])->toBe(2)
        ->and($result['total'])->toBe(4)
        ->and($result['buy_ratio'])->toBeGreaterThanOrEqual(0.01)
        ->and($result['sell_ratio'])->toBeGreaterThanOrEqual(0.01);
});

it('rejects a period when transaction costs leave either BUY or SELL below the viability floor', function () {
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
        ->and(min($result['buy_ratio'], $result['sell_ratio']))->toBeLessThan(0.01);
});


it('excludes context-only endpoints from finalized viability counts', function (int $count, int $expectedTotal) {
    $candles = array_fill(0, $count, [
        ...viabilityCandle('100', '100'), 'action' => 'hold',
    ]);
    $result = app(CandlePeriodViability::class)->evaluate($candles, 0.005);

    expect($result['hold'])->toBe($expectedTotal)
        ->and($result['total'])->toBe($expectedTotal)
        ->and($result['buy'])->toBe(0)
        ->and($result['sell'])->toBe(0)
        ->and($result['passes'])->toBeFalse()
        ->and($candles)->toHaveCount($count);
    if ($candles !== []) {
        expect($candles[0])->not->toHaveKey('action')
            ->and($candles[$count - 1])->not->toHaveKey('action');
    }
})->with([[0, 0], [1, 0], [2, 0], [3, 1], [6, 4]]);

it('does not tag either endpoint of a rising or falling sequence', function (bool $rising) {
    $candles = [];
    for ($index = 0; $index < 8; $index++) {
        $open = $rising ? 100 + $index : 100 - $index;
        $close = $rising ? $open + 1 : $open - 1;
        $candles[] = viabilityCandle((string) $open, (string) $close);
    }
    app(CandlePeriodViability::class)->evaluate($candles, 0.005);
    expect($candles[0])->not->toHaveKey('action')
        ->and($candles[7])->not->toHaveKey('action')
        ->and(array_column($candles, 'action'))->not->toBeEmpty();
})->with(['rising' => [true], 'falling' => [false]]);
