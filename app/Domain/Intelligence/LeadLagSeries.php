<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\TickerRepository;

/** UTC close-indexed observations. Missing bars are never forward-filled. */
final class LeadLagSeries
{
    public static function step(string $period): ?int
    {
        return in_array($period, CandleTimeframe::SUPPORTED, true) && preg_match('/^\d+[mhdw]$/D', $period)
            ? (new CandleTimeframe)->next(0, $period) : null;
    }

    public function load(string $exchange, string $symbol, string $period, int $fromMs, int $asOfMs): array
    {
        $asOfMs = min($asOfMs, now()->getTimestampMs());
        $step = self::step($period);
        if ($step === null) {
            return [];
        }
        $result = [];
        $previous = null;
        foreach (app(TickerRepository::class)->streamHistory($exchange, $symbol, $period,
            max(0, $fromMs - 2 * $step), $asOfMs - $step) as $time => $bar) {
            $valid = is_array($bar);
            foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                $valid = $valid && is_numeric($bar[$key] ?? null) && is_finite((float) $bar[$key]);
            }
            if (! $valid || min($bar['open'], $bar['high'], $bar['low'], $bar['close']) <= 0
                || $bar['volume'] <= 0 || $bar['high'] <= $bar['low']
                || $bar['high'] < max($bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                $previous = null;

                continue;
            }
            if ($previous !== null && $time === $previous['time'] + $step && $time + $step >= $fromMs) {
                $result[$time + $step] = ['return' => log((float) $bar['close']) - log($previous['close']),
                    'volume' => (float) $bar['volume'], 'range' => ((float) $bar['high'] - (float) $bar['low']) / (float) $bar['close']];
            }
            $previous = ['time' => $time, 'close' => (float) $bar['close']];
        }

        return $result;
    }
}
