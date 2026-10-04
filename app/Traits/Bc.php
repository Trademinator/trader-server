<?php

namespace App\Traits;

use InvalidArgumentException;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

trait Bc
{
    /**
     * Maximum decimal places, retaining the historical minimum of two.
     * Use bcdec(...$values, trimTrailingZeros: true) to ignore fractional padding.
     * The named option is captured by the variadic parameter, so existing
     * single-value and multiple-value calls keep their meaning.
     */
    public function bcdec(...$numbers): int
    {
        $trimTrailingZeros = $numbers['trimTrailingZeros'] ?? false;
        unset($numbers['trimTrailingZeros']);
        if (! is_bool($trimTrailingZeros)) {
            throw new InvalidArgumentException('bcdec trimTrailingZeros must be a boolean.');
        }
        $dec = 2;

        foreach ($numbers as $number) {
            // Normalized decimal strings need only a string scan. Expand
            // exponent notation (and already-float input) only when necessary.
            $value = is_float($number) || (is_string($number) && strpbrk($number, 'eE') !== false)
                ? $this->bcconv($number)
                : (string) $number;
            $dot = strpos($value, '.');
            if ($dot === false) {
                continue;
            }
            $fraction = substr($value, $dot + 1);
            if ($trimTrailingZeros) {
                $fraction = rtrim($fraction, '0');
            }
            $dec = max(strlen($fraction), $dec);
        }

        return $dec;
    }

    public function bclog10($n): float
    {
        if (! is_numeric($n) || (float) $n <= 0) {
            throw new InvalidArgumentException('bclog10 expects a positive numeric value.');
        }

        return log10((float) $n);
    }

    public function bcabs($number): string
    {
        return ltrim((string) $number, '-');
    }

    /** Expand numeric input into a BCMath decimal without float-rounding strings. */
    public function bcconv($number): string
    {
        if (is_float($number)) {
            if (! is_finite($number)) {
                throw new InvalidArgumentException('bcconv expects a finite numeric value.');
            }
            $precision = ini_get('serialize_precision');
            try {
                ini_set('serialize_precision', '-1');
                $number = json_encode($number, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            } finally {
                ini_set('serialize_precision', $precision);
            }
        }
        if ((! is_int($number) && ! is_string($number))
            || ! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', (string) $number, $parts)
            || (($parts[2] ?? '') === '' && ($parts[3] ?? '') === '')) {
            throw new InvalidArgumentException('bcconv expects a finite numeric value.');
        }
        $integer = $parts[2] === '' ? '0' : $parts[2];
        $fraction = $parts[3] ?? '';
        $exponent = $parts[4] ?? '0';
        if (strlen(ltrim($exponent, '+-0')) > 4 || abs((int) $exponent) > 4096
            || strlen($integer) + strlen($fraction) > 4096) {
            throw new InvalidArgumentException('bcconv input exceeds the supported decimal length.');
        }
        $digits = $integer.$fraction;
        $point = strlen($integer) + (int) $exponent;
        if ($point <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $point - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $sign = $parts[1] === '-' && trim($integer.$fraction, '0') !== '' ? '-' : '';

        return $sign.$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    public function bcmax(...$values): string|false
    {
        if ($values === []) {
            return false;
        }

        $max = (string) $values[0];
        foreach ($values as $value) {
            if (bccomp((string) $value, $max, EXCHANGE_ROUND_DECIMALS * 2) === 1) {
                $max = (string) $value;
            }
        }

        return $max;
    }

    public function bcmin(...$values): string|false
    {
        if ($values === []) {
            return false;
        }

        $min = (string) $values[0];
        foreach ($values as $value) {
            if (bccomp($min, (string) $value, EXCHANGE_ROUND_DECIMALS * 2) === 1) {
                $min = (string) $value;
            }
        }

        return $min;
    }

    public function stats_standard_deviation(array $a, bool $sample = false): float|string|false
    {
        $n = count($a);
        if ($n === 0) {
            trigger_error('The array has zero elements', E_USER_WARNING);

            return false;
        }

        if ($sample && $n === 1) {
            trigger_error('The array has only 1 element', E_USER_WARNING);

            return false;
        }

        $sum = '0';
        foreach ($a as $value) {
            $sum = bcadd((string) $value, $sum, EXCHANGE_ROUND_DECIMALS * 2);
        }
        $mean = bcdiv($sum, (string) $n, EXCHANGE_ROUND_DECIMALS * 2);

        $carry = '0';
        foreach ($a as $value) {
            $delta = bcsub((string) $value, $mean, EXCHANGE_ROUND_DECIMALS * 2);
            $carry = bcadd(
                $carry,
                bcmul($delta, $delta, EXCHANGE_ROUND_DECIMALS * 2),
                EXCHANGE_ROUND_DECIMALS * 2
            );
        }

        if ($sample) {
            $n--;
        }

        return bcsqrt(bcdiv($carry, (string) $n, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
    }

    public function bcpow10(int $n, int $scale = 10): string
    {
        if ($n === 0) {
            return '1';
        }

        if ($n > 0) {
            return bcpow('10', (string) $n, $scale);
        }

        $denominator = bcpow('10', (string) abs($n), 0);

        return bcdiv('1', $denominator, $scale);
    }
}
