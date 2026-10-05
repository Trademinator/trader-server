<?php

namespace App\Domain\Research;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use InvalidArgumentException;

final class FeatureSchema
{
    public const BOUNDARY_TOLERANCE = 1e-12;

    public static function keys(string $schema, array $custom = []): array
    {
        $all = array_merge(FeatureEngine::KEYS, ContextFeatures::KEYS);
        if ($schema !== 'custom' && $custom !== []) {
            throw new InvalidArgumentException('--features requires --schema=custom.');
        }
        $keys = match ($schema) {
            'core' => array_values(array_diff(FeatureEngine::KEYS, ['return.24h', 'return.7d', 'return.30d'])),
            'technical' => FeatureEngine::KEYS,
            'full' => $all,
            'custom' => $custom,
            default => throw new InvalidArgumentException('Schema must be core, technical, full, or custom.'),
        };
        if ($keys === [] || count($keys) !== count(array_unique($keys)) || array_diff($keys, $all) !== []) {
            throw new InvalidArgumentException('Feature keys must be nonempty, unique, known M2 feature names.');
        }

        return $keys;
    }

    public static function vector(array $payload, array $keys): ?array
    {
        if (($payload['source_available_at_ms'] ?? 0) > ($payload['available_at_ms'] ?? 0)) {
            return null;
        }
        $vector = [];
        foreach ($keys as $key) {
            $value = $payload['features'][$key] ?? null;
            if ($value === null) {
                return null;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Invalid numeric feature: '.$key);
            }
            $direction = in_array($key, ['trend.direction', 'candle.direction'], true);
            if (($direction && ! in_array($value, [-1, 0, 1, -1.0, 0.0, 1.0], true))
                || (! $direction && ($value < -self::BOUNDARY_TOLERANCE || $value > 1 + self::BOUNDARY_TOLERANCE))) {
                throw new InvalidArgumentException('Feature outside its M2 range: '.$key);
            }
            // M2 float arithmetic can round a theoretical endpoint just past 0 or 1.
            $vector[] = $direction ? $value : max(0, min(1, $value));
        }

        return $vector;
    }
}
