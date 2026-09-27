<?php

namespace App\Domain\Research;

use App\Enums\TradeAction;
use InvalidArgumentException;

/** Next-open entry, exit at the close of the horizon-th subsequent candle. */
final readonly class LabelDefinition
{
    public const VERSION = 'm3-next-open-v1';

    public function __construct(
        public int $horizon = 12,
        public float $feeBps = 10,
        public float $slippageBps = 5,
        public float $minimumReturnBps = 10,
    ) {
        if ($horizon < 1 || $horizon > 10000) {
            throw new InvalidArgumentException('Horizon must be an integer from 1 to 10000 candles.');
        }
        foreach ([$feeBps, $slippageBps, $minimumReturnBps] as $value) {
            if (! is_finite($value) || $value < 0 || $value >= 10000) {
                throw new InvalidArgumentException('Basis-point values must be finite, nonnegative and below 10000.');
            }
        }
    }

    public function label(float $entry, float $exit): array
    {
        if (! is_finite($entry) || ! is_finite($exit) || min($entry, $exit) <= 0) {
            throw new InvalidArgumentException('Label prices must be finite and positive.');
        }
        $fee = $this->feeBps / 10000;
        $slippage = $this->slippageBps / 10000;
        $factor = (1 - $fee) ** 2 * (1 - $slippage) / (1 + $slippage);
        $buy = $exit / $entry * $factor - 1;
        // Sell existing base, then repurchase: change in base units versus holding.
        // This is a bearish target, not a short-sale execution assumption.
        $sell = $entry / $exit * $factor - 1;
        if (! is_finite($buy) || ! is_finite($sell)) {
            throw new InvalidArgumentException('Label return overflow.');
        }
        $threshold = $this->minimumReturnBps / 10000;

        return [
            'action' => ($buy > $threshold ? TradeAction::BUY : ($sell > $threshold ? TradeAction::SELL : TradeAction::HODL))->value,
            'gross_return' => $exit / $entry - 1,
            'buy_net_return' => $buy,
            'sell_base_net_return' => $sell,
        ];
    }

    public function metadata(): array
    {
        return ['version' => self::VERSION, 'horizon' => $this->horizon, 'fee_bps' => $this->feeBps,
            'slippage_bps' => $this->slippageBps, 'minimum_return_bps' => $this->minimumReturnBps,
            'entry' => 'next_candle_open', 'exit' => 'horizon_candle_close'];
    }
}
