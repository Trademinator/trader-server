<?php

namespace App\Domain\MarketData;

/** Missing intervals are observations, never synthetic zero-volume candles. */
final class CandleGaps
{
    /**
     * Bisect expected candle slots, stopping at complete or entirely empty ranges.
     * The summary must count distinct, grid-aligned timestamps, not OHLCV payloads.
     *
     * @param  callable(int, int): array{count: int, first: ?int, last: ?int}  $summary
     * @return list<array{from: int, to: int}> Inclusive missing candle start times.
     */
    public function find(int $fromMs, int $untilMs, string $period, callable $summary): array
    {
        $timeframe = new CandleTimeframe;
        $next = $timeframe->next($fromMs, $period);
        if ($next > $untilMs) {
            return [];
        }

        if (str_ends_with($period, 'M') || str_ends_with($period, 'y')) {
            $starts = [];
            for ($cursor = $fromMs; $timeframe->next($cursor, $period) <= $untilMs; $cursor = $timeframe->next($cursor, $period)) {
                $starts[] = $cursor;
            }
            $expected = count($starts);
            $timestamp = fn (int $index): int => $starts[$index];
        } else {
            $step = $next - $fromMs;
            $expected = intdiv($untilMs - $fromMs, $step);
            $timestamp = fn (int $index): int => $fromMs + $index * $step;
        }

        $gaps = [];
        $this->bisect(0, $expected - 1, $timestamp, $summary, $gaps, $period);

        return $gaps;
    }

    /**
     * @param  list<array{from: int, to: int}>  $ranges
     * @return list<array{from: int, to: int}>
     */
    public function merge(array $ranges, string $period): array
    {
        usort($ranges, fn (array $left, array $right): int => $left['from'] <=> $right['from']);
        $merged = [];
        foreach ($ranges as $range) {
            $this->append($merged, $range['from'], $range['to'], $period);
        }

        return $merged;
    }

    private function bisect(int $left, int $right, callable $timestamp, callable $summary, array &$gaps, string $period): void
    {
        $from = $timestamp($left);
        $to = $timestamp($right);
        $actual = $summary($from, $to);
        $expected = $right - $left + 1;

        if ($actual['count'] === 0) {
            $this->append($gaps, $from, $to, $period);

            return;
        }
        if ($actual['count'] === $expected && $actual['first'] === $from && $actual['last'] === $to) {
            return;
        }
        if ($left === $right || $actual['count'] > $expected) {
            throw new \UnexpectedValueException('Candle range statistics must contain distinct, aligned timestamps.');
        }

        $middle = $left + intdiv($right - $left, 2);
        $this->bisect($left, $middle, $timestamp, $summary, $gaps, $period);
        $this->bisect($middle + 1, $right, $timestamp, $summary, $gaps, $period);
    }

    private function append(array &$ranges, int $from, int $to, string $period): void
    {
        $last = array_key_last($ranges);
        if ($last !== null && (new CandleTimeframe)->next($ranges[$last]['to'], $period) >= $from) {
            $ranges[$last]['to'] = max($ranges[$last]['to'], $to);
        } else {
            $ranges[] = ['from' => $from, 'to' => $to];
        }
    }

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
