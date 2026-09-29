<?php

namespace App\Domain\Research;

use InvalidArgumentException;

/** Public-price turning-point targets; client fees never enter server knowledge. */
final readonly class SemanticLabels
{
    public const VERSION = 'm4-turning-points-v1';

    public function __construct(
        public int $horizon = 12,
        public int $lookback = 20,
        public float $minimumMoveBps = 10,
        public float $extremeFraction = 0.2,
    ) {
        if ($horizon < 2 || $horizon > 1000 || $lookback < 2 || $lookback > 1000
            || ! is_finite($minimumMoveBps) || $minimumMoveBps < 0 || $minimumMoveBps >= 10000
            || ! is_finite($extremeFraction) || $extremeFraction <= 0 || $extremeFraction >= 0.5) {
            throw new InvalidArgumentException('Invalid semantic label horizon, lookback, movement or extreme fraction.');
        }
    }

    public function label(array $past, array $future): array
    {
        $close = (float) $future[0]['close'];
        $closes = array_map(fn (array $bar): float => (float) $bar['close'], $past);
        $low = min($closes);
        $high = max($closes);
        $position = $high > $low ? ($close - $low) / ($high - $low) : 0.5;
        $movement = (float) $future[$this->horizon]['close'] / $close - 1;
        $bottom = $high > $low && $position <= $this->extremeFraction;
        $top = $high > $low && $position >= 1 - $this->extremeFraction;
        $action = $bottom && $movement > $this->minimumMoveBps / 10000 ? 'buy'
            : ($top && $movement < -$this->minimumMoveBps / 10000 ? 'sell' : 'hodl');
        $entry = (float) $future[1]['open'];
        $exit = (float) $future[$this->horizon]['close'];

        return [
            'action' => $action, 'gross_return' => $exit / $entry - 1,
            'buy_net_return' => $exit / $entry - 1, 'sell_base_net_return' => $entry / $exit - 1,
            'semantic' => ['position' => $position, 'bottom' => $bottom, 'top' => $top, 'movement' => $movement],
        ];
    }

    public function metadata(): array
    {
        return ['version' => self::VERSION, 'horizon' => $this->horizon, 'lookback' => $this->lookback,
            'minimum_move_bps' => $this->minimumMoveBps, 'extreme_fraction' => $this->extremeFraction,
            'fee_bps' => 0, 'slippage_bps' => 0, 'target' => 'trailing_close_extreme_and_future_close_reversal'];
    }
}
