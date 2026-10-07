<?php

namespace App\Domain\Intelligence;

final class HumanTrainingWeight
{
    public const MAXIMUM = 0.60;

    public const TARGET_SAMPLES = 750;

    public static function human(int $samples): float
    {
        if ($samples <= 0) {
            return 0.0;
        }

        return min(self::MAXIMUM, self::MAXIMUM * sqrt($samples / self::TARGET_SAMPLES));
    }

    public static function algorithmic(int $samples): float
    {
        return 1.0 - self::human($samples);
    }
}
