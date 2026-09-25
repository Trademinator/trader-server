<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandleTimeframe;

it('advances calendar months by actual calendar boundaries', function () {
    $january = strtotime('2026-01-01 00:00:00 UTC') * 1000;
    $february = strtotime('2026-02-01 00:00:00 UTC') * 1000;
    $march = strtotime('2026-03-01 00:00:00 UTC') * 1000;
    $timeframe = new CandleTimeframe;

    expect($timeframe->next($january, '1M'))->toBe($february)
        ->and($timeframe->next($february, '1M'))->toBe($march)
        ->and($timeframe->next($january, '1y'))->toBe(strtotime('2027-01-01 00:00:00 UTC') * 1000);
});

it('reports an observed gap without creating a synthetic candle', function () {
    $gap = (new CandleGaps)->between([180_000, 0, 180_000], '1m');

    expect($gap)->toBe([['from' => 60_000, 'to' => 179_999]]);
});
