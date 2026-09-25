<?php

namespace App\Domain\MarketData;

/** Missing intervals are observations, never synthetic zero-volume candles. */
final class CandleGaps
{
    /** @return list<array{from: int, to: int}> Bounds are candle start times in milliseconds. */
    public function between(array $timestamps, string $period): array
    {
        $timestamps = array_values(array_unique(array_map('intval', $timestamps)));
        sort($timestamps, SORT_NUMERIC);
        $timeframe = new CandleTimeframe;
        $gaps = [];

        for ($i = 1; $i < count($timestamps); $i++) {
            $expected = $timeframe->next($timestamps[$i - 1], $period);
            if ($expected < $timestamps[$i]) {
                $gaps[] = ['from' => $expected, 'to' => $timestamps[$i] - 1];
            }
        }

        return $gaps;
    }
}
