<?php

use App\Domain\MarketData\CandleSyncPages;

it('bounds each page and overlaps boundaries without skipping the requested end', function () {
    $planner = new CandleSyncPages;
    $from = 0;
    $windows = [];

    do {
        $page = $planner->window($from, 1200, '1m', 10);
        $windows[] = [$page['from'], $page['to']];
        $from = $page['next'];
    } while ($from !== null);

    expect($windows)->toBe([[0, 599], [420, 1019], [840, 1200]]);
});

it('handles calendar-month pages and validates the page size', function () {
    $from = strtotime('2026-01-01 00:00:00 UTC');
    $to = strtotime('2027-01-01 00:00:00 UTC');
    $page = (new CandleSyncPages)->window($from, $to, '1M', 10);

    expect($page['to'])->toBe(strtotime('2026-11-01 00:00:00 UTC') - 1)
        ->and($page['next'])->toBeGreaterThan($from)
        ->and($page['next'])->toBeLessThan($page['to']);

    expect(fn () => (new CandleSyncPages)->window($from, $to, '1m', 101))
        ->toThrow(InvalidArgumentException::class);
});
