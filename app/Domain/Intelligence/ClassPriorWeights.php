<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/** Adjust votes, never examples. Frequencies must come from training data only. */
final class ClassPriorWeights
{
    public const ACTIONS = ['buy', 'hold', 'sell'];

    public const TARGET = ['buy' => 0.25, 'hold' => 0.50, 'sell' => 0.25];

    /** @return array<string, float> */
    public static function fit(array $counts, ?array $target = null): array
    {
        foreach (self::ACTIONS as $action) {
            if (! isset($counts[$action]) || ! is_int($counts[$action]) || $counts[$action] < 0) {
                throw new InvalidArgumentException('Class counts must be nonnegative integers.');
            }
        }
        if (array_diff(array_keys($counts), self::ACTIONS) !== [] || array_sum($counts) < 1) {
            throw new InvalidArgumentException('Provide nonempty BUY/HOLD/SELL training counts.');
        }
        if ($target === null) {
            return array_fill_keys(self::ACTIONS, 1.0);
        }
        if (array_diff(self::ACTIONS, array_keys($target)) !== [] || count($target) !== 3) {
            throw new InvalidArgumentException('A target weight is required for each action.');
        }
        foreach ($target as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
                throw new InvalidArgumentException('Target weights must be finite and positive.');
            }
        }
        if (abs(array_sum($target) - 1.0) > 1e-9) {
            throw new InvalidArgumentException('Target weights must sum to one.');
        }
        $total = array_sum($counts);
        $weights = [];
        foreach (self::ACTIONS as $action) {
            // A missing class contributes no evidence, even when its target is positive.
            $weights[$action] = $counts[$action] === 0 ? 0.0 : $target[$action] * $total / $counts[$action];
        }

        return $weights;
    }

    /** Equivalent to weighting each actual distance-weighted neighbour by its class. */
    public static function apply(array $shares, array $weights): array
    {
        $votes = [];
        foreach (self::ACTIONS as $action) {
            $share = $shares[$action] ?? 0.0;
            $weight = $weights[$action] ?? null;
            foreach ([$share, $weight] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                    throw new InvalidArgumentException('Class shares and weights must be finite and nonnegative.');
                }
            }
            $votes[$action] = $share * $weight;
        }
        $total = array_sum($votes);
        if (! is_finite($total)) {
            throw new InvalidArgumentException('Class vote total is not finite.');
        }

        return $total > 0 ? array_map(fn (float $vote): float => $vote / $total, $votes) : $votes;
    }
}
