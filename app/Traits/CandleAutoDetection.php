<?php

namespace App\Traits;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

/**
 * Retrospective helpers for M4 Candle Training labels.
 *
 * The order of these routines is intentional: early passes over-label candidate
 * actions and later passes remove or move excess labels. These labels describe
 * training opportunities from historical CLOSE values; they are not Client
 * execution instructions.
 */
trait CandleAutoDetection
{
    use Patterns;

    // All black candles are BUY candidates; all white candles are SELL candidates.
    public function mark_all_blacks_and_whites(array &$tickers): array
    {
        $count = count($tickers);
        foreach ($tickers as $index => &$ticker) {
            if ($index <= 0 || $index >= $count - 2) {
                continue;
            }
            if (($ticker['is_black()'] ?? 0) === 1) {
                $ticker['action'] = 'buy';
            } elseif (($ticker['is_white()'] ?? 0) === 1) {
                $ticker['action'] = 'sell';
            }
        }
        unset($ticker);

        return $tickers;
    }

    // Remove consecutive identical actions; only the last candidate matters.
    public function remove_consequitive_actions(array &$tickers): array
    {
        if ($tickers === []) {
            return $tickers;
        }

        $count = count($tickers);
        $lastStateIndex = 0;
        for ($index = 1; $index < $count - 2; $index++) {
            if (isset($tickers[$index]['action'], $tickers[$lastStateIndex]['action'])
                && $tickers[$index]['action'] === $tickers[$lastStateIndex]['action']) {
                unset($tickers[$lastStateIndex]['action']);
            }

            if (($tickers[$index]['is_super_doji()'] ?? 0) === 0) {
                $lastStateIndex = $index;
            }
        }

        return $tickers;
    }

    // Remove BUY -> SELL pairs whose CLOSE movement cannot clear round-trip taker fees.
    // Deliberately no ATR here: this pass is only the economic floor.
    public function remove_unprofitable_transactions(array &$tickers, mixed $taker_fee): array
    {
        $minProfit = $this->candle_auto_transaction_cost_floor($taker_fee);
        $index = 0;

        while (($firstBuyIndex = $this->candle_auto_find_next_action_index($tickers, 'buy', $index)) !== null) {
            $index = $firstBuyIndex + 1;
            $firstSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index);
            if ($firstSellIndex === null) {
                continue;
            }
            $index = $firstSellIndex + 1;

            $buyClose = $this->bcconv($tickers[$firstBuyIndex]['close']);
            $sellClose = $this->bcconv($tickers[$firstSellIndex]['close']);
            if (bccomp($buyClose, '0', EXCHANGE_ROUND_DECIMALS * 2) <= 0) {
                unset($tickers[$firstBuyIndex]['action'], $tickers[$firstSellIndex]['action']);
                continue;
            }

            $profit = bcsub(
                bcdiv($sellClose, $buyClose, EXCHANGE_ROUND_DECIMALS * 2),
                '1',
                EXCHANGE_ROUND_DECIMALS * 2
            );
            if (bccomp($buyClose, $sellClose, EXCHANGE_ROUND_DECIMALS * 2) >= 0
                || bccomp($profit, $minProfit, EXCHANGE_ROUND_DECIMALS * 2) < 0) {
                unset($tickers[$firstBuyIndex]['action'], $tickers[$firstSellIndex]['action']);
            }
        }

        return $tickers;
    }

    // Remove SELL -> BUY zigzags that do not clear both the fee floor and 1x ATRP(3, EMA).
    public function remove_zigzags(array &$tickers, mixed $taker_fee): array
    {
        if ($tickers === []) {
            return $tickers;
        }

        $minProfit = $this->candle_auto_transaction_cost_floor($taker_fee);
        $keyAtrp3 = $this->atrp($tickers, 3, 'ema');
        $index = 0;

        while (($firstSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index)) !== null) {
            $index = $firstSellIndex + 1;
            $nextBuyIndex = $this->candle_auto_find_next_action_index($tickers, 'buy', $index);
            if ($nextBuyIndex === null) {
                continue;
            }
            $index = $nextBuyIndex + 1;

            $sellClose = $this->bcconv($tickers[$firstSellIndex]['close']);
            $buyClose = $this->bcconv($tickers[$nextBuyIndex]['close']);
            if (bccomp($buyClose, '0', EXCHANGE_ROUND_DECIMALS * 2) <= 0) {
                unset($tickers[$firstSellIndex]['action'], $tickers[$nextBuyIndex]['action']);
                continue;
            }

            $offset = $this->bcabs(bcsub(
                '1',
                bcdiv($sellClose, $buyClose, EXCHANGE_ROUND_DECIMALS * 2),
                EXCHANGE_ROUND_DECIMALS * 2
            ));
            // atrp() is a percentage; movement/fee floors are decimal fractions.
            $atrpFloor = bcdiv(
                $this->bcconv($tickers[$nextBuyIndex][$keyAtrp3] ?? '0'),
                '100',
                EXCHANGE_ROUND_DECIMALS * 2
            );
            $minimumMovement = $this->bcmax($minProfit, $atrpFloor);

            if (bccomp($offset, $minimumMovement, EXCHANGE_ROUND_DECIMALS * 2) <= 0) {
                unset($tickers[$firstSellIndex]['action'], $tickers[$nextBuyIndex]['action']);
            }
        }

        return $tickers;
    }

    // Between surviving SELLs, move the BUY label to the lowest black CLOSE.
    public function find_new_bottoms(array &$tickers): array
    {
        $index = 0;
        while (($firstSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index)) !== null) {
            $index = $firstSellIndex + 1;
            $nextSellIndex = $this->candle_auto_find_next_action_index($tickers, 'sell', $index);
            if ($nextSellIndex === null || $nextSellIndex - $firstSellIndex <= 1) {
                continue;
            }

            $newBottomIndex = null;
            for ($candidate = $firstSellIndex + 1; $candidate < $nextSellIndex; $candidate++) {
                if (($tickers[$candidate]['is_black()'] ?? 0) !== 1) {
                    continue;
                }
                if ($newBottomIndex === null || bccomp(
                    $this->bcconv($tickers[$candidate]['close']),
                    $this->bcconv($tickers[$newBottomIndex]['close']),
                    EXCHANGE_ROUND_DECIMALS * 2
                ) < 0) {
                    $newBottomIndex = $candidate;
                }
            }
            if ($newBottomIndex === null) {
                continue;
            }

            $firstBuyIndex = $this->candle_auto_find_next_action_index($tickers, 'buy', $firstSellIndex + 1);
            if ($firstBuyIndex !== null && $firstBuyIndex < $nextSellIndex && $firstBuyIndex !== $newBottomIndex) {
                unset($tickers[$firstBuyIndex]['action']);
            }
            $tickers[$newBottomIndex]['action'] = 'buy';
        }

        return $tickers;
    }

    // Keep the old behaviour: only completely flat OHLC candles are auto-HOLD.
    public function hodl_all_dojis(array &$tickers): array
    {
        foreach ($tickers as &$ticker) {
            if (($ticker['is_super_doji()'] ?? 0) === 1) {
                $ticker['action'] = 'hold';
            }
        }
        unset($ticker);

        return $tickers;
    }

    // Label interior candles in long same-colour chains as HOLD candidates.
    public function hodl_middle_chains(array &$tickers): array
    {
        $count = count($tickers);
        for ($index = 2; $index < $count - 2; $index++) {
            if (($tickers[$index]['is_black()'] ?? 0) === 1
                && ($tickers[$index - 1]['is_black()'] ?? 0) === 1
                && ($tickers[$index + 1]['is_black()'] ?? 0) === 1
                && ($tickers[$index + 2]['is_black()'] ?? 0) === 1) {
                $tickers[$index]['action'] = 'hold';
            } elseif (($tickers[$index]['is_white()'] ?? 0) === 1
                && ($tickers[$index - 1]['is_white()'] ?? 0) === 1
                && ($tickers[$index + 1]['is_white()'] ?? 0) === 1
                && ($tickers[$index + 2]['is_white()'] ?? 0) === 1) {
                $tickers[$index]['action'] = 'hold';
            }
        }

        return $tickers;
    }

    private function candle_auto_find_next_action_index(array $tickers, string $action, int $start = 0): ?int
    {
        for ($index = max(0, $start); $index < count($tickers); $index++) {
            if (($tickers[$index]['action'] ?? null) === $action) {
                return $index;
            }
        }

        return null;
    }

    private function candle_auto_transaction_cost_floor(mixed $taker_fee): string
    {
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $fee = $this->bcconv($taker_fee);
        if (bccomp($fee, '0', $scale) < 0 || bccomp($fee, '1', $scale) >= 0) {
            throw new \InvalidArgumentException('Taker fee must be a decimal fraction between zero and one.');
        }
        $ownerRemaining = bcsub('1', $fee, $scale);

        return bcsub('1', bcmul($ownerRemaining, $ownerRemaining, $scale), $scale);
    }
}
