<?php

namespace App\Domain\MarketData;

/** Compact per-scan metadata for compressed history that SQL cannot count directly. */
final class CandleTimestampIndex
{
    private string $timestamps = '';

    private int $count = 0;

    /** @param iterable<int> $timestamps Distinct or duplicate timestamps in ascending order. */
    public function __construct(iterable $timestamps, string $period)
    {
        $timeframe = new CandleTimeframe;
        $calendar = str_ends_with($period, 'M') || str_ends_with($period, 'y');
        $anchor = $expected = $previous = null;
        $step = null;
        foreach ($timestamps as $timestamp) {
            $timestamp = (int) $timestamp;
            if ($previous !== null && $timestamp < $previous) {
                throw new \UnexpectedValueException('Candle timestamps must be ordered.');
            }
            if ($timestamp === $previous) {
                continue;
            }
            $previous = $timestamp;
            $anchor ??= $timestamp;
            $expected ??= $timestamp;
            $step ??= $timeframe->next($anchor, $period) - $anchor;

            if ($calendar) {
                while ($expected < $timestamp) {
                    $expected = $timeframe->next($expected, $period);
                }
                if ($timestamp !== $expected) {
                    continue;
                }
            } elseif (($timestamp - $anchor) % $step !== 0) {
                continue;
            }

            $this->timestamps .= pack('J', $timestamp);
            $this->count++;
        }
    }

    public function first(): ?int
    {
        return $this->count === 0 ? null : $this->timestamp(0);
    }

    /** @return array{count: int, first: ?int, last: ?int} */
    public function summary(int $fromMs, int $toMs): array
    {
        $first = $this->lowerBound($fromMs);
        $end = $this->lowerBound($toMs + 1);

        return [
            'count' => $end - $first,
            'first' => $first < $end ? $this->timestamp($first) : null,
            'last' => $first < $end ? $this->timestamp($end - 1) : null,
        ];
    }

    private function lowerBound(int $timestamp): int
    {
        $left = 0;
        $right = $this->count;
        while ($left < $right) {
            $middle = $left + intdiv($right - $left, 2);
            if ($this->timestamp($middle) < $timestamp) {
                $left = $middle + 1;
            } else {
                $right = $middle;
            }
        }

        return $left;
    }

    private function timestamp(int $index): int
    {
        return unpack('J', $this->timestamps, $index * 8)[1];
    }
}
