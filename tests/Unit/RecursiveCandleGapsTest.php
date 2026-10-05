<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandleTimestampIndex;

it('skips complete halves and merges a hole crossing the midpoint', function () {
    $stored = array_map(fn (int $slot): int => $slot * 60000, array_values(array_diff(range(0, 999), [499, 500, 777])));
    $index = new CandleTimestampIndex($stored, '1m');
    $probes = [];

    $gaps = (new CandleGaps)->find(0, 60_000_000, '1m', function (int $from, int $to) use ($index, &$probes): array {
        $probes[] = [$from, $to];

        return $index->summary($from, $to);
    });

    expect($gaps)->toBe([
        ['from' => 29_940_000, 'to' => 30_000_000],
        ['from' => 46_620_000, 'to' => 46_620_000],
    ]);
    expect(count($probes))->toBeLessThan(65);
    expect($probes)->not->toContain([0, 60000]);
});

it('stops after one summary for an empty or complete interval', function (array $stored, array $expected) {
    $index = new CandleTimestampIndex($stored, '1m');
    $probes = 0;

    $gaps = (new CandleGaps)->find(0, 180000, '1m', function (int $from, int $to) use ($index, &$probes): array {
        $probes++;

        return $index->summary($from, $to);
    });

    expect($gaps)->toBe($expected);
    expect($probes)->toBe(1);
})->with([
    'empty' => [[], [['from' => 0, 'to' => 120000]]],
    'complete' => [[0, 60000, 120000], []],
]);

it('finds leading and trailing holes without counting the open candle', function () {
    $index = new CandleTimestampIndex([60000, 120000, 240000], '1m');

    $gaps = (new CandleGaps)->find(0, 270000, '1m', $index->summary(...));

    expect($gaps)->toBe([['from' => 0, 'to' => 0], ['from' => 180000, 'to' => 180000]]);
});

it('uses real calendar boundaries when bisecting monthly candles', function () {
    $index = new CandleTimestampIndex([
        strtotime('2024-01-01 UTC') * 1000,
        strtotime('2024-04-01 UTC') * 1000,
    ], '1M');

    $gaps = (new CandleGaps)->find(strtotime('2024-01-01 UTC') * 1000, strtotime('2024-04-15 UTC') * 1000, '1M', $index->summary(...));

    expect($gaps)->toBe([[
        'from' => strtotime('2024-02-01 UTC') * 1000,
        'to' => strtotime('2024-03-01 UTC') * 1000,
    ]]);
});

it('does not let duplicates or off-grid timestamps hide missing slots', function () {
    $index = new CandleTimestampIndex([0, 0, 30000, 120000, 150000, 180000], '1m');

    $gaps = (new CandleGaps)->find(0, 240000, '1m', $index->summary(...));

    expect($gaps)->toBe([['from' => 60000, 'to' => 60000]]);
});

it('merges adjacent and overlapping repair pages but preserves complete intervals between holes', function () {
    $gaps = (new CandleGaps)->merge([
        ['from' => 600000, 'to' => 600000],
        ['from' => 60000, 'to' => 120000],
        ['from' => 120000, 'to' => 240000],
        ['from' => 300000, 'to' => 360000],
    ], '1m');

    expect($gaps)->toBe([['from' => 60000, 'to' => 360000], ['from' => 600000, 'to' => 600000]]);
});
