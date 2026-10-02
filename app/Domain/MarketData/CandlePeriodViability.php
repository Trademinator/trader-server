<?php

namespace App\Domain\MarketData;

use App\Traits\CandleAutoDetection;
use InvalidArgumentException;

/** Run the M4/M5 retrospective auto-label pipeline without persisting labels. */
final class CandlePeriodViability
{
    use CandleAutoDetection;

    /**
     * Mutate the candle buffer in place so 7/14-day samples are not duplicated.
     *
     * @param  list<array<string, mixed>>  $candles
     * @return array{passes: bool, buy: int, sell: int, hold: int, total: int, buy_ratio: float, sell_ratio: float, minimum_ratio: float}
     */
    public function evaluate(array &$candles, float $takerFee, float $minimumRatio = 0.01): array
    {
        if (! is_finite($takerFee) || $takerFee < 0 || $takerFee >= 1
            || ! is_finite($minimumRatio) || $minimumRatio < 0 || $minimumRatio > 1) {
            throw new InvalidArgumentException('Invalid candle-period economic viability settings.');
        }

        if ($candles === []) {
            return $this->summary([], $minimumRatio);
        }

        $this->candle_anatomy($candles);
        $this->mark_all_blacks_and_whites($candles);
        $this->remove_consequitive_actions($candles);
        $this->remove_unprofitable_transactions($candles, $takerFee);
        $this->remove_zigzags($candles, $takerFee);
        $this->find_new_bottoms($candles);
        $this->hodl_all_dojis($candles);
        $this->hodl_middle_chains($candles);

        return $this->summary($candles, $minimumRatio);
    }

    /** @param list<array<string, mixed>> $tickers */
    private function summary(array $tickers, float $minimumRatio): array
    {
        $counts = ['buy' => 0, 'sell' => 0, 'hold' => 0];
        foreach ($tickers as $ticker) {
            $action = $ticker['action'] ?? null;
            if (is_string($action) && isset($counts[$action])) {
                $counts[$action]++;
            }
        }

        // Deliberately exclude unlabelled candles. The gate is defined over
        // finalized BUY + SELL + HOLD auto-detected signals.
        $total = array_sum($counts);
        $buyRatio = $total === 0 ? 0.0 : $counts['buy'] / $total;
        $sellRatio = $total === 0 ? 0.0 : $counts['sell'] / $total;

        return [
            'passes' => $total > 0 && $buyRatio >= $minimumRatio && $sellRatio >= $minimumRatio,
            ...$counts,
            'total' => $total,
            'buy_ratio' => (float) $buyRatio,
            'sell_ratio' => (float) $sellRatio,
            'minimum_ratio' => $minimumRatio,
        ];
    }
}
