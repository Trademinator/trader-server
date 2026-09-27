<?php

namespace App\Domain\Research;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ResearchInput
{
    public static function integer(mixed $value, string $name, int $minimum = 1, int $maximum = 50000): int
    {
        if (! is_scalar($value) || ! preg_match('/^\d+$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$name} must be an integer from {$minimum} to {$maximum}.");
        }

        return (int) $value;
    }

    public static function bps(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value >= 10000) {
            throw new InvalidArgumentException('Basis-point values must be numeric, nonnegative and below 10000.');
        }

        return (float) $value;
    }

    /** Explicit UTC ISO dates or epoch milliseconds; never relative/local dates. */
    public static function timestamp(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (ctype_digit($value)) {
            return self::integer($value, 'Timestamp', 0, 253402300799999);
        }
        $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\TH:i:s\Z';
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format(substr($format, 1)) !== $value || $date->getTimestamp() < 0) {
            throw new InvalidArgumentException('Use UTC YYYY-MM-DD, YYYY-MM-DDTHH:MM:SSZ, or nonnegative Unix milliseconds.');
        }

        return $date->getTimestamp() * 1000;
    }
}
