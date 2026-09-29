<?php

namespace App\Domain\MarketData;

use App\Traits\Bc;
use InvalidArgumentException;

/** UTC-aligned fixed periods; missing or unfinished buckets are never synthesized. */
final class ClosedCandleAggregator
{
    use Bc;

    public function duration(string $period): int
    {
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || ! preg_match('/^(\d+)([mhd])$/D', $period, $parts)) {
            throw new InvalidArgumentException('Derived periods must be supported fixed minute, hour or day periods.');
        }

        return (int) $parts[1] * match ($parts[2]) {
            'm' => 60000, 'h' => 3600000, 'd' => 86400000,
        };
    }

    public function rows(iterable $source, string $base, string $period, int $cutoffMs): \Generator
    {
        $baseMs = $this->duration($base);
        $targetMs = $this->duration($period);
        if ($targetMs <= $baseMs || $targetMs % $baseMs !== 0) {
            throw new InvalidArgumentException('Derived period must be a larger exact multiple of its base period.');
        }
        $bucket = $previous = null;
        $bar = null;
        $count = 0;
        foreach ($source as $raw) {
            $timestamp = $raw['microtimestamp'];
            if (! is_int($timestamp) || $timestamp < 0 || $timestamp % $baseMs !== 0
                || ($previous !== null && $timestamp <= $previous)) {
                throw new InvalidArgumentException('Base candles must be unique, chronological and UTC-aligned.');
            }
            if ($timestamp + $baseMs > $cutoffMs) {
                break;
            }
            $currentBucket = intdiv($timestamp, $targetMs) * $targetMs;
            if ($currentBucket !== $bucket) {
                $bucket = $currentBucket;
                $bar = null;
                $count = 0;
            }
            foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                $raw[$key] = $this->bcconv($raw[$key]);
                if (bccomp($raw[$key], '0', $this->bcdec($raw[$key])) < ($key === 'volume' ? 0 : 1)) {
                    throw new InvalidArgumentException('Invalid base candle bounds.');
                }
            }
            $scale = $this->bcdec($raw['open'], $raw['close'], $raw['high'], $raw['low']);
            if (bccomp($raw['high'], $raw['low'], $scale) < 0
                || bccomp($raw['high'], $raw['open'], $scale) < 0 || bccomp($raw['high'], $raw['close'], $scale) < 0
                || bccomp($raw['low'], $raw['open'], $scale) > 0 || bccomp($raw['low'], $raw['close'], $scale) > 0) {
                throw new InvalidArgumentException('Invalid base candle geometry.');
            }
            if ($timestamp === $bucket) {
                $bar = $raw;
                $bar['derived_from'] = $base;
                $bar['derivation_version'] = 'm4-closed-utc-v1';
            } elseif ($bar !== null && $previous + $baseMs === $timestamp) {
                $scale = $this->bcdec($bar['high'], $bar['low'], $raw['high'], $raw['low']);
                $bar['high'] = bccomp($raw['high'], $bar['high'], $scale) > 0 ? $raw['high'] : $bar['high'];
                $bar['low'] = bccomp($raw['low'], $bar['low'], $scale) < 0 ? $raw['low'] : $bar['low'];
                $bar['close'] = $raw['close'];
                $bar['volume'] = bcadd($bar['volume'], $raw['volume'], $this->bcdec($bar['volume'], $raw['volume']));
            } else {
                $bar = null;
            }
            $previous = $timestamp;
            $count++;
            if ($bar !== null && $count === intdiv($targetMs, $baseMs) && $bucket + $targetMs <= $cutoffMs) {
                yield $bar;
            }
        }
    }
}
