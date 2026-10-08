<?php

namespace App\Domain\Research;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use InvalidArgumentException;

final class FeatureSchema
{
    public const BOUNDARY_TOLERANCE = 1e-12;
    public const VERSION = 'knn-schema-v2-optional-coingecko';

    public static function baseSchema(string $schema): string
    {
        return match ($schema) {
            'core', 'enhanced' => 'core',
            'technical', 'full' => 'technical',
            'custom' => 'custom',
            default => throw new InvalidArgumentException('Unknown feature schema: '.$schema),
        };
    }

    public static function modelCompatible(array $report): bool
    {
        $schema = $report['outcome']['schema'] ?? $report['training_data']['schema'] ?? null;
        if (! is_string($schema) || ! is_array($report['keys'] ?? null)) {
            return false;
        }
        try {
            if ($schema === 'custom') {
                $keys = array_values($report['keys']);
                return $keys !== [] && count($keys) === count(array_unique($keys))
                    && array_diff($keys, array_merge(FeatureEngine::KEYS, ContextFeatures::KEYS)) === [];
            }
            return array_values($report['keys']) === self::keys($schema);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function keys(string $schema, array $custom = []): array
    {
        $all = array_merge(FeatureEngine::KEYS, ContextFeatures::KEYS);
        if ($schema !== 'custom' && $custom !== []) {
            throw new InvalidArgumentException('--features requires --schema=custom.');
        }
        $keys = match ($schema) {
            'core', 'enhanced' => array_values(array_diff(FeatureEngine::KEYS, ['return.24h', 'return.7d', 'return.30d'])),
            'technical', 'full' => FeatureEngine::KEYS,
            'custom' => $custom,
            default => throw new InvalidArgumentException('Schema must be core, technical, enhanced, full, or custom.'),
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
