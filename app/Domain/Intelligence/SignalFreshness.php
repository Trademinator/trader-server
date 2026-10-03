<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;

final class SignalFreshness
{
    public function __construct(private CandleTimeframe $timeframe) {}

    public function expiresAt(
        ?int $decisionAtMs,
        string $period,
        ?int $maxAgePeriods = null,
        ?int $maxAgeSeconds = null,
    ): ?int {
        if ($decisionAtMs === null || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return null;
        }

        $maxAgePeriods ??= (int) config('intelligence.max_signal_age_periods');
        $maxAgeSeconds ??= (int) config('intelligence.max_signal_age_seconds');

        $periodExpiry = $decisionAtMs;
        for ($i = 0; $i < max(0, $maxAgePeriods); $i++) {
            $periodExpiry = $this->timeframe->next($periodExpiry, $period);
        }

        $wallClockExpiry = $decisionAtMs + max(60, $maxAgeSeconds) * 1000;

        return min($periodExpiry, $wallClockExpiry);
    }
}
