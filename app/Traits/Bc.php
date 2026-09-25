<?php

namespace App\Traits;

use InvalidArgumentException;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

trait Bc
{
    public function bcdec(...$numbers): int
    {
        $dec = 2;

        foreach ($numbers as $number) {
            $value = (string) $number;
            $dot = strrchr($value, '.');
            $dec = max($dot === false ? 0 : strlen(substr($dot, 1)), $dec);
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

    public function bcconv($number): string
    {
        if (! is_numeric($number)) {
            throw new InvalidArgumentException('bcconv expects a numeric value.');
        }

        $value = (float) $number;
        if (! is_finite($value)) {
            throw new InvalidArgumentException('bcconv expects a finite numeric value.');
        }

        if ($value == 0.0) {
            return '0';
        }

        $precision = max(1, (int) ini_get('precision'));
        $decimals = max(0, $precision - (int) floor(log10(abs($value))) - 1);
        $formatted = number_format($value, $decimals, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
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
