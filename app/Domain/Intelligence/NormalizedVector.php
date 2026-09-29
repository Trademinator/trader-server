<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\FeatureSchema;
use InvalidArgumentException;

final class NormalizedVector
{
    public const VERSION = 'm4-unit-interval-v1';

    /** Keep M2/M3 artifacts intact; apply exactly the same transform at train and inference. */
    public static function from(array $vector, array $keys): array
    {
        if (! array_is_list($vector) || count($vector) !== count($keys) || $keys === []) {
            throw new InvalidArgumentException('Feature dimensions do not match the model schema.');
        }
        foreach ($keys as $i => $key) {
            $value = $vector[$i];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Non-finite or missing model feature: '.$key);
            }
            if (in_array($key, ['trend.direction', 'candle.direction'], true)) {
                if (! in_array($value, [-1, 0, 1, -1.0, 0.0, 1.0], true)) {
                    throw new InvalidArgumentException('Invalid directional feature: '.$key);
                }
                $value = ($value + 1) / 2;
            }
            if ($value < -FeatureSchema::BOUNDARY_TOLERANCE || $value > 1 + FeatureSchema::BOUNDARY_TOLERANCE) {
                throw new InvalidArgumentException('Model feature outside [0, 1]: '.$key);
            }
            $vector[$i] = (float) max(0, min(1, $value));
        }

        return $vector;
    }
}
