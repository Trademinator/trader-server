<?php

use App\Domain\MarketData\CandleQuality;

it('selects the shortest period meeting the candle quality threshold', function () {
    $flat = array_fill(0, 50, ['open' => '1', 'high' => '1', 'low' => '1', 'close' => '1', 'volume' => '0']);
    $moving = array_map(fn ($n) => ['open' => (string) $n, 'high' => (string) ($n + 2), 'low' => (string) ($n - 1), 'close' => (string) ($n + 1), 'volume' => '1'], range(1, 50));
    $quality = new CandleQuality;
    $selected = $quality->choose(['1m' => $flat, '5m' => $moving, '15m' => $moving], 0.01, 0.7);

    expect($selected['period'])->toBe('5m')
        ->and($selected['quality']['score'])->toBeGreaterThanOrEqual(0.7)
        ->and($quality->evaluate($flat, 0.01)['true_flat_ratio'])->toBe(1.0);
});

it('rejects too-small samples rather than treating them as high quality', function () {
    $candle = ['open' => 1, 'high' => 2, 'low' => 1, 'close' => 2, 'volume' => 1];
    expect((new CandleQuality)->choose(['1m' => [$candle]], 0.01))->toBeNull();
});

it('rejects sparse candles despite strong price movement', function () {
    $candles = array_map(fn (int $n): array => [
        'microtimestamp' => $n * 300_000,
        'open' => (string) $n, 'high' => (string) ($n + 2),
        'low' => (string) ($n - 1), 'close' => (string) ($n + 1), 'volume' => '1',
    ], range(1, 50));

    expect((new CandleQuality)->choose(['1m' => $candles], 0.01, 0.7))->toBeNull();
});

it('does not count a moving open-equals-close candle as truly flat', function () {
    $candles = array_map(fn (int $n): array => [
        'open' => (string) $n, 'high' => (string) ($n + 2),
        'low' => (string) ($n - 2), 'close' => (string) $n, 'volume' => '5',
    ], range(10, 59));

    $metrics = (new CandleQuality)->evaluate($candles, 0.01);

    expect($metrics['true_flat_ratio'])->toBe(0.0)
        ->and($metrics['score'])->toBeGreaterThan(0.7);
});
