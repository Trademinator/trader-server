<?php

namespace App\Domain\Intelligence;

/** Identity of what a trainer reviewed, excluding later observations and revision metadata. */
final class SnapshotInput
{
    public static function digest(array $payload): string
    {
        unset($payload['model_observation'], $payload['revision']);

        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR));
    }

    public static function profile(array $payload): string
    {
        return hash('sha256', json_encode([
            $payload['version'] ?? null, $payload['feature_version'] ?? null,
            $payload['normalization'] ?? null, $payload['keys'] ?? null,
            $payload['horizon_candles'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public static function key(string $marketKey, int $decision, array $payload): string
    {
        return hash('sha256', $marketKey.'|'.$decision.'|'.self::digest($payload));
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = self::canonical($child);
            }
        }

        return $value;
    }
}
