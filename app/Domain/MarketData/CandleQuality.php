<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

final class CandleQuality
{
    /** @return array{score: float, true_flat_ratio: float, longest_flat_run_ratio: float, zero_volume_ratio: float, unique_close_ratio: float, median_range_ticks: float, median_range_relative: float, sample_size: int} */
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
        $closes = $ranges = $relativeRanges = [];

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
                || ! is_finite($high - $low)
                || $high < max($open, $close) || $low > min($open, $close) || $low > $high || $volume < 0) {
                throw new InvalidArgumentException('Invalid OHLCV values.');
            }

            $flat = $open === $high && $high === $low && $low === $close;
            $flats += (int) $flat;
            $run = $flat ? $run + 1 : 0;
            $longestRun = max($longestRun, $run);
            $zeros += (int) ($volume === 0.0);
            // Decimal formatting (1, 1.0, 1.000) does not change uniqueness.
            $closes[(string) (float) $candle['close']] = true;
            $ranges[] = ($high - $low) / $tickSize;
            $relativeRanges[] = ($high - $low) / max(abs($close), $tickSize);
        }

        sort($ranges, SORT_NUMERIC);
        sort($relativeRanges, SORT_NUMERIC);
        $middle = intdiv($count, 2);

        $median = $count % 2
            ? (float) $ranges[$middle]
            : (float) (($ranges[$middle - 1] + $ranges[$middle]) / 2);
        $relativeMedian = $count % 2
            ? (float) $relativeRanges[$middle]
            : (float) (($relativeRanges[$middle - 1] + $relativeRanges[$middle]) / 2);

        $flatRatio = (float) ($flats / $count);
        $runRatio = (float) ($longestRun / $count);
        $zeroRatio = (float) ($zeros / $count);
        $uniqueRatio = (float) (count($closes) / $count);
        $rangeScore = (float) ((min(1.0, $median / 5.0) + min(1.0, $relativeMedian / 0.001)) / 2);
        $score = (float) max(
            0.0,
            min(
                1.0,
                ((1 - $flatRatio) + (1 - $runRatio) + (1 - $zeroRatio) + $uniqueRatio + $rangeScore) / 5
            )
        );

        return [
            'score' => $score,
            'true_flat_ratio' => $flatRatio,
            'longest_flat_run_ratio' => $runRatio,
            'zero_volume_ratio' => $zeroRatio,
            'unique_close_ratio' => $uniqueRatio,
            'median_range_ticks' => $median,
            'median_range_relative' => $relativeMedian,
            'sample_size' => $count,
        ];
    }

    /** The periods must be supplied from shortest to longest. */
    public function choose(array $samplesByPeriod, float $tickSize, float $threshold = 0.7, int $minimumCandles = 50, float $minimumCoverage = 0.8): ?array
    {
        if (! is_finite($threshold) || $threshold < 0 || $threshold > 1 || $minimumCandles < 1
            || ! is_finite($minimumCoverage) || $minimumCoverage < 0 || $minimumCoverage > 1) {
            throw new InvalidArgumentException('Invalid selection threshold, coverage or minimum sample size.');
        }

        foreach ($samplesByPeriod as $period => $candles) {
            if (count($candles) < $minimumCandles) {
                continue;
            }

            $quality = $this->evaluate($candles, $tickSize);
            if (isset($candles[0]['microtimestamp'])) {
                $timestamps = array_column($candles, 'microtimestamp');
                sort($timestamps, SORT_NUMERIC);
                $missing = (new CandleGaps)->between($timestamps, (string) $period);
                $timeframe = new CandleTimeframe;
                $missingCount = 0;
                foreach ($missing as $gap) {
                    for ($at = $gap['from']; $at <= $gap['to']; $at = $timeframe->next($at, (string) $period)) {
                        $missingCount++;
                        if ($missingCount > 100_000) {
                            break 2;
                        }
                    }
                }
                $quality['coverage_ratio'] = (float) (count(array_unique($timestamps)) / (count(array_unique($timestamps)) + $missingCount));
            }
            if ($quality['score'] >= $threshold && ($quality['coverage_ratio'] ?? 1.0) >= $minimumCoverage) {
                return ['period' => $period, 'quality' => $quality];
            }
        }

        return null;
    }
}
