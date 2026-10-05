<?php

namespace App\Domain\Intelligence;

use Closure;
use RuntimeException;

/** Optional evidence cannot consume the publication reserve of the base model. */
final class OptionalGuidance
{
    public static function enabled(string $mode): bool
    {
        return (bool) config('human_training.enabled') && (bool) config('human_training.'.$mode.'_enabled', true);
    }

    public static function compare(string $mode, string $version, float $deadline, Closure $compare): array
    {
        $skipped = fn (string $reason): array => ['bundle' => [
            'version' => $version, 'status' => $reason, 'optional' => true,
            'influence' => false, 'keys' => [], 'samples' => 0,
        ]];
        if (! self::enabled($mode)) {
            return $skipped('disabled');
        }
        $reserve = max(1.0, (float) config('human_training.publication_reserve_seconds', 10));
        $budget = max(1.0, (float) config('human_training.auxiliary_max_seconds', 90));
        $auxiliaryDeadline = min($deadline - $reserve, microtime(true) + $budget);
        if ($auxiliaryDeadline <= microtime(true)) {
            return $skipped('optional_budget_exhausted');
        }
        try {
            $result = $compare($auxiliaryDeadline);
            $result['bundle']['optional'] = true;

            return $result;
        } catch (RuntimeException $error) {
            // Only known computation-budget errors are recoverable here. In particular,
            // corrupt snapshots, source/checksum errors and programming errors propagate.
            if (! in_array($error->getMessage(), [
                'Human guidance training time budget exceeded.',
                'Candle guidance training time budget exceeded.',
                'K tuning time budget exceeded; reduce intelligence.max_rows or train_size.',
                'KNN evaluation time budget exceeded.',
            ], true)) {
                throw $error;
            }

            return $skipped('optional_budget_exhausted');
        }
    }
}
