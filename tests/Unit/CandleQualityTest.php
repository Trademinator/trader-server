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
