<?php

namespace App\Domain\Research;

use InvalidArgumentException;

/**
 * Five-class, cost-free Outcome target.
 *
 * H is derived from Action pivot frequency before the dataset is frozen.
 * Future CLOSEs define a regression slope; only past/current candles define V.
 */
final readonly class SemanticLabels
{
    public const VERSION = 'm5-outcome-slope-v1';

    public const OUTCOMES = ['super_bear', 'bear', 'neutral', 'bull', 'super_bull'];

    public const NEUTRAL_BOUNDARY = 0.20;

    public const SUPER_BOUNDARY = 0.60;

    public function __construct(
        public int $horizon = 12,
        public int $lookback = 20,
    ) {
        if ($horizon < 1 || $horizon > 1000 || $lookback < 2 || $lookback > 1000) {
            throw new InvalidArgumentException('Invalid Outcome horizon or volatility lookback.');
        }
    }

    public function withHorizon(int $horizon): self
    {
        return new self($horizon, $this->lookback);
    }

    public function label(array $past, array $future): array
    {
        if (count($future) <= $this->horizon) {
            throw new InvalidArgumentException('Outcome labeling requires the complete forward horizon.');
        }

        $closes = [];
        for ($i = 0; $i <= $this->horizon; $i++) {
            $close = (float) ($future[$i]['close'] ?? 0);
            if ($close <= 0 || ! is_finite($close)) {
                throw new InvalidArgumentException('Outcome labeling requires positive finite CLOSE prices.');
            }
            $closes[] = log($close);
        }

        $meanX = $this->horizon / 2;
        $meanY = array_sum($closes) / count($closes);
        $numerator = $denominator = 0.0;
        foreach ($closes as $i => $logClose) {
            $dx = $i - $meanX;
            $numerator += $dx * ($logClose - $meanY);
            $denominator += $dx * $dx;
        }
        $beta = $denominator > 0 ? $numerator / $denominator : 0.0;

        $currentClose = (float) $future[0]['close'];
        $atr = $this->atr($past);
        $volatility = $currentClose > 0 ? $atr / $currentClose : 0.0;
        $normalized = $volatility > 1e-12
            ? $beta * sqrt($this->horizon) / $volatility
            : ($beta > 0 ? INF : ($beta < 0 ? -INF : 0.0));
        $m = is_infinite($normalized) ? ($normalized > 0 ? 1.0 : -1.0) : tanh($normalized);
        $outcome = self::classify($m);

        $entry = (float) $future[1]['open'];
        $exit = (float) $future[$this->horizon]['close'];

        return [
            'action' => $outcome,
            'gross_return' => $exit / $entry - 1,
            'buy_price_return' => $exit / $entry - 1,
            'sell_base_price_return' => $entry / $exit - 1,
            'semantic' => [
                'm' => $m,
                'beta' => $beta,
                'volatility' => $volatility,
                'atr' => $atr,
            ],
        ];
    }

    public static function classify(float $m): string
    {
        if (! is_finite($m) || $m < -1.0 || $m > 1.0) {
            throw new InvalidArgumentException('Outcome slope M must be finite and between -1 and 1.');
        }

        return match (true) {
            $m < -self::SUPER_BOUNDARY => 'super_bear',
            $m < -self::NEUTRAL_BOUNDARY => 'bear',
            $m <= self::NEUTRAL_BOUNDARY => 'neutral',
            $m <= self::SUPER_BOUNDARY => 'bull',
            default => 'super_bull',
        };
    }

    public function metadata(): array
    {
        return [
            'version' => self::VERSION,
            'horizon' => $this->horizon,
            'lookback' => $this->lookback,
            'horizon_source' => 'action_pivot_frequency_weighted_mean',
            'formula' => 'M=tanh(beta*sqrt(H)/V)',
            'neutral_boundary' => self::NEUTRAL_BOUNDARY,
            'super_boundary' => self::SUPER_BOUNDARY,
            'cost_model' => 'none',
            'fee_bps' => 0,
            'slippage_bps' => 0,
            'target' => 'forward_volatility_normalized_log_close_slope',
        ];
    }

    private function atr(array $past): float
    {
        if ($past === []) {
            throw new InvalidArgumentException('Outcome volatility requires historical candles.');
        }

        $trueRanges = [];
        $previousClose = null;
        foreach ($past as $bar) {
            $high = (float) ($bar['high'] ?? 0);
            $low = (float) ($bar['low'] ?? 0);
            $close = (float) ($bar['close'] ?? 0);
            if ($high <= 0 || $low <= 0 || $close <= 0) {
                throw new InvalidArgumentException('Outcome volatility requires positive OHLC history.');
            }
            $range = $high - $low;
            if ($previousClose !== null) {
                $range = max($range, abs($high - $previousClose), abs($low - $previousClose));
            }
            $trueRanges[] = $range;
            $previousClose = $close;
        }

        return array_sum($trueRanges) / count($trueRanges);
    }
}
