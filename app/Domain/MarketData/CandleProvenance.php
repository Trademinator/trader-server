<?php

namespace App\Domain\MarketData;

final class CandleProvenance
{
    public const VERSION = 'isolated-candle-v1';

    public static function method(array $candle): ?string
    {
        $evidence = $candle['reconstruction'] ?? null;
        if ($evidence === null) {
            return null;
        }
        if (! is_array($evidence) || ($evidence['version'] ?? null) !== self::VERSION
            || ! in_array($evidence['method'] ?? null, ['lower_timeframe', 'no_trades', 'next_open'], true)
            || ! is_int($evidence['available_at_ms'] ?? null) || $evidence['available_at_ms'] < 0) {
            throw new \InvalidArgumentException('Invalid reconstructed candle provenance.');
        }

        return $evidence['method'];
    }

    public static function availableAt(array $candle, string $period): int
    {
        $timeframe = new CandleTimeframe;
        $close = $timeframe->next((int) $candle['microtimestamp'], $period);
        $method = self::method($candle);
        if ($method === 'next_open') {
            $close = $timeframe->next($close, $period);
        }

        return $method === null ? $close : max($close, $candle['reconstruction']['available_at_ms']);
    }

    /** @return array<string, int> */
    public static function counts(array $candles): array
    {
        $counts = [];
        foreach ($candles as $candle) {
            $method = self::method($candle);
            if ($method !== null) {
                $counts[$method] = ($counts[$method] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
