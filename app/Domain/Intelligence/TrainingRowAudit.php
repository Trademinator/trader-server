<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/** Deduplicate candle identities, not repeated states or repeated HOLD decisions. */
final class TrainingRowAudit
{
    /** @return array{rows: array, input_rows: int, unique_rows: int, duplicates: int} */
    public static function inspect(array $rows): array
    {
        $unique = [];
        $digests = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            $decision = $row['decision_at_ms'] ?? null;
            if (! is_int($decision) || $decision < 1) {
                throw new InvalidArgumentException('A training row requires an integer decision timestamp.');
            }
            $digest = hash('sha256', json_encode(self::canonical($row), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if (isset($digests[$decision])) {
                if (! hash_equals($digests[$decision], $digest)) {
                    throw new InvalidArgumentException('Conflicting training rows share decision timestamp '.$decision.'.');
                }
                $duplicates++;
                continue;
            }
            $digests[$decision] = $digest;
            $unique[$decision] = $row;
        }
        ksort($unique, SORT_NUMERIC);

        return ['rows' => array_values($unique), 'input_rows' => count($rows),
            'unique_rows' => count($unique), 'duplicates' => $duplicates];
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
