<?php

namespace App\Helpers;

use RoundingMode;

use function Trademinator\BcMath\bcconv;

final class Decimal
{
    /**
     * Preserve decimal strings and expand exponents without a float round trip.
     * For floats, bcconv uses PHP's shortest round-trippable decimal representation;
     * it cannot undo precision lost in earlier binary arithmetic.
     */
    public static function normalize(int|float|string $number): string
    {
        return bcconv(is_string($number) ? trim($number) : $number);
    }

    /** Format a decimal with half-away-from-zero rounding and optional grouping. */
    public static function format(
        int|float|string|null $number,
        int $decimals = 0,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ',',
    ): string {
        $rounded = bcround(self::normalize($number ?? 0), $decimals, RoundingMode::HalfAwayFromZero);
        [$integer, $fraction] = array_pad(explode('.', $rounded, 2), 2, '');

        if ($thousandsSeparator !== '') {
            $integer = preg_replace_callback('/\d(?=(?:\d{3})+$)/',
                static fn (array $match): string => $match[0].$thousandsSeparator, $integer);
        }

        return $integer.($decimals > 0 ? $decimalSeparator.$fraction : '');
    }
}
