<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

final class CandleQuality
{
    /** @return array{score: float, true_flat_ratio: float, longest_flat_run_ratio: float, zero_volume_ratio: float, unique_close_ratio: float, median_range_ticks: float, sample_size: int} */
    public function evaluate(array $candles, float $tickSize): array
    {
        if ($tickSize <= 0 || ! is_finite($tickSize)) {
            throw new InvalidArgumentException('Tick size must be positive and finite.');
        }
        $count = count($candles);
        if ($count === 0) {
            throw new InvalidArgumentException('At least one candle is required.');
        }
        $flats = $zeros = $run = $longestRun = 0;
        $closes = $ranges = [];
        foreach ($candles as $candle) {
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! isset($candle[$field]) || ! is_numeric($candle[$field])) {
                    throw new InvalidArgumentException("Invalid candle field: {$field}");
                }
            }
            $open = (float) $candle['open'];
            $high = (float) $candle['high'];
            $low = (float) $candle['low'];
            $close = (float) $candle['close'];
            $volume = (float) $candle['volume'];
            if (! is_finite($open) || ! is_finite($high) || ! is_finite($low) || ! is_finite($close) || ! is_finite($volume)
                || $high < max($open, $close) || $low > min($open, $close) || $low > $high || $volume < 0) {
                throw new InvalidArgumentException('Invalid OHLCV values.');
            }
            $flat = $open === $high && $high === $low && $low === $close;
            $flats += (int) $flat;
            $run = $flat ? $run + 1 : 0;
            $longestRun = max($longestRun, $run);
            $zeros += (int) ($volume === 0.0);
            $closes[(string) $candle['close']] = true;
            $ranges[] = ($high - $low) / $tickSize;
        }
        sort($ranges, SORT_NUMERIC);
        $middle = intdiv($count, 2);
        $median = $count % 2 ? $ranges[$middle] : ($ranges[$middle - 1] + $ranges[$middle]) / 2;
        $flatRatio = $flats / $count;
        $runRatio = $longestRun / $count;
        $zeroRatio = $zeros / $count;
        $uniqueRatio = count($closes) / $count;
        $rangeScore = min(1.0, $median / 5.0);

        return [
            'score' => max(0.0, min(1.0, ((1 - $flatRatio) + (1 - $runRatio) + (1 - $zeroRatio) + $uniqueRatio + $rangeScore) / 5)),
            'true_flat_ratio' => $flatRatio,
            'longest_flat_run_ratio' => $runRatio,
            'zero_volume_ratio' => $zeroRatio,
            'unique_close_ratio' => $uniqueRatio,
            'median_range_ticks' => $median,
            'sample_size' => $count,
        ];
    }

    /** The periods must be supplied from shortest to longest. */
    public function choose(array $samplesByPeriod, float $tickSize, float $threshold = 0.7, int $minimumCandles = 50): ?array
    {
        if ($threshold < 0 || $threshold > 1 || $minimumCandles < 1) {
            throw new InvalidArgumentException('Invalid selection threshold or minimum sample size.');
        }
        foreach ($samplesByPeriod as $period => $candles) {
            if (count($candles) < $minimumCandles) {
                continue;
            }
            $quality = $this->evaluate($candles, $tickSize);
            if ($quality['score'] >= $threshold) {
                return ['period' => $period, 'quality' => $quality];
            }
        }

        return null;
    }
}
