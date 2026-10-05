<?php

namespace App\Domain\Client;

use App\Helpers\Decimal;
use InvalidArgumentException;

final class PaperExecutionModel
{
    private const SCALE = 18;

    public function benchmark(
        string $initialQuote,
        string $ask,
        string $feeBps,
        string $slippageBps,
        string $feeAsset,
    ): array {
        $this->guardFeeAsset($feeAsset);
        $feeRate = bcdiv($feeBps, '10000', self::SCALE);
        $price = $this->slippedPrice('buy', $ask, $ask, $slippageBps);

        if ($feeAsset === 'quote') {
            $quote = bcdiv($initialQuote, bcadd('1', $feeRate, self::SCALE), self::SCALE);
            $base = bcdiv($quote, $price, self::SCALE);
        } else {
            $grossBase = bcdiv($initialQuote, $price, self::SCALE);
            $base = bcsub($grossBase, bcmul($grossBase, $feeRate, self::SCALE), self::SCALE);
        }

        return ['price' => $price, 'base_quantity' => $base];
    }

    public function fill(
        string $side,
        string $baseBudget,
        string $quoteBudget,
        string $bid,
        string $ask,
        string $feeBps,
        string $slippageBps,
        string $feeAsset,
        mixed $amountStep = null,
        mixed $minimumAmount = null,
        mixed $minimumCost = null,
    ): array {
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw new InvalidArgumentException('Paper side must be buy or sell.');
        }
        $this->guardFeeAsset($feeAsset);
        $feeRate = bcdiv($feeBps, '10000', self::SCALE);
        $price = $this->slippedPrice($side, $bid, $ask, $slippageBps);

        if ($side === 'buy') {
            $quantity = $this->applyStep(bcdiv($quoteBudget, $price, self::SCALE), $amountStep);
        } elseif ($feeAsset === 'base') {
            $quantity = $this->applyStep(
                bcdiv($baseBudget, bcadd('1', $feeRate, self::SCALE), self::SCALE),
                $amountStep
            );
        } else {
            $quantity = $this->applyStep($baseBudget, $amountStep);
        }

        $quote = bcmul($quantity, $price, self::SCALE);
        $constraint = $this->constraint($quantity, $quote, $minimumAmount, $minimumCost);
        if ($constraint !== null) {
            return ['eligible' => false, 'reason' => $constraint];
        }

        $feeQuote = '0';
        $feeBase = '0';
        if ($feeAsset === 'quote') {
            $feeQuote = bcmul($quote, $feeRate, self::SCALE);
        } else {
            $feeBase = bcmul($quantity, $feeRate, self::SCALE);
        }
        $feeQuoteEquivalent = $feeAsset === 'quote'
            ? $feeQuote
            : bcmul($feeBase, $price, self::SCALE);

        if ($side === 'buy') {
            $baseCredit = $feeAsset === 'base' ? bcsub($quantity, $feeBase, self::SCALE) : $quantity;
            if (bccomp($baseCredit, '0', self::SCALE) <= 0) {
                return ['eligible' => false, 'reason' => 'paper_fee_consumes_fill'];
            }

            return [
                'eligible' => true, 'reason' => 'paper_fill',
                'quantity' => $quantity, 'price' => $price, 'quote_amount' => $quote,
                'quote_debit' => bcadd($quote, $feeQuote, self::SCALE), 'quote_credit' => '0',
                'base_debit' => '0', 'base_credit' => $baseCredit,
                'fee_asset' => $feeAsset, 'fee_amount' => $feeAsset === 'quote' ? $feeQuote : $feeBase,
                'fee_quote' => $feeQuote, 'fee_base' => $feeBase,
                'fee_quote_equivalent' => $feeQuoteEquivalent,
            ];
        }

        return [
            'eligible' => true, 'reason' => 'paper_fill',
            'quantity' => $quantity, 'price' => $price, 'quote_amount' => $quote,
            'quote_debit' => '0', 'quote_credit' => bcsub($quote, $feeQuote, self::SCALE),
            'base_debit' => bcadd($quantity, $feeBase, self::SCALE), 'base_credit' => '0',
            'fee_asset' => $feeAsset, 'fee_amount' => $feeAsset === 'quote' ? $feeQuote : $feeBase,
            'fee_quote' => $feeQuote, 'fee_base' => $feeBase,
            'fee_quote_equivalent' => $feeQuoteEquivalent,
        ];
    }

    private function slippedPrice(string $side, string $bid, string $ask, string $slippageBps): string
    {
        $rate = bcdiv($slippageBps, '10000', self::SCALE);
        $price = $side === 'buy'
            ? bcmul($ask, bcadd('1', $rate, self::SCALE), self::SCALE)
            : bcmul($bid, bcsub('1', $rate, self::SCALE), self::SCALE);

        if (bccomp($price, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Paper slippage must leave a positive fill price.');
        }

        return $price;
    }

    private function applyStep(string $amount, mixed $step): string
    {
        if ($step === null || bccomp($this->d($step), '0', self::SCALE) <= 0) {
            return $amount;
        }
        $step = $this->d($step);
        $units = bcdiv($amount, $step, 0);

        return bcmul($units, $step, self::SCALE);
    }

    private function constraint(string $amount, string $cost, mixed $minimumAmount, mixed $minimumCost): ?string
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return 'amount_rounds_to_zero';
        }
        if ($minimumAmount !== null && bccomp($amount, $this->d($minimumAmount), self::SCALE) < 0) {
            return 'below_exchange_minimum_amount';
        }
        if ($minimumCost !== null && bccomp($cost, $this->d($minimumCost), self::SCALE) < 0) {
            return 'below_exchange_minimum_cost';
        }

        return null;
    }

    private function guardFeeAsset(string $feeAsset): void
    {
        if (! in_array($feeAsset, ['quote', 'base'], true)) {
            throw new InvalidArgumentException('Paper fee asset must be quote or base.');
        }
    }

    private function d(mixed $value): string
    {
        return Decimal::normalize($value);
    }
}
