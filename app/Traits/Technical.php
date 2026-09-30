<?php

namespace App\Traits;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

trait Technical
{
    use Bc, TickerManipulation;

    public function normalize(&$tickers, $key = 'close', $index = 'close')
    {
        global $debug;

        if ($debug) {
            echo "normalize(tickers, $key = 'close', $index = 'close')".PHP_EOL;
        }

        $normalized_key = 'normalized('.$key.','.$index.')';
        $t = end($tickers);
        if (! array_key_exists($normalized_key, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most recent
                if (bccomp($h[$index], 0, EXCHANGE_ROUND_DECIMALS * 2) > 0) {
                    $h[$normalized_key] = bcdiv($h[$key], $h[$index], EXCHANGE_ROUND_DECIMALS * 2);
                } else {
                    $h[$normalized_key] = 0;
                }
            }
        }

        return $normalized_key;
    }

    /** True range for one candle. */
    public function technical_true_range_value(mixed $high, mixed $low, mixed $previousClose = null): string
    {
        $scale = EXCHANGE_ROUND_DECIMALS;
        $high = (string) $high;
        $low = (string) $low;
        $previousClose = $previousClose === null ? $low : (string) $previousClose;

        return $this->bcmax(
            bcsub($high, $low, $scale),
            $this->bcabs(bcsub($high, $previousClose, $scale)),
            $this->bcabs(bcsub($previousClose, $low, $scale))
        );
    }

    /** Typical price for one candle. */
    public function technical_typical_price_value(mixed $high, mixed $low, mixed $close): string
    {
        $scale = EXCHANGE_ROUND_DECIMALS;
        $sum = bcadd((string) $high, (string) $low, $scale);
        $sum = bcadd($sum, (string) $close, $scale);

        return bcdiv($sum, '3', $scale);
    }

    /** Signed fractional change (current-base)/base. */
    public function technical_relative_change_value(mixed $current, mixed $base): ?string
    {
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $base = (string) $base;
        if (bccomp($base, '0', $scale) === 0) {
            return null;
        }

        return bcdiv(bcsub((string) $current, $base, $scale), $base, $scale);
    }

    /** Signed difference divided by a third value. */
    public function technical_normalized_difference_value(mixed $a, mixed $b, mixed $denominator): ?string
    {
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $denominator = (string) $denominator;
        if (bccomp($denominator, '0', $scale) === 0) {
            return null;
        }

        return bcdiv(bcsub((string) $a, (string) $b, $scale), $denominator, $scale);
    }

    /** Candle geometry fractions, calculated entirely with BCMath. */
    public function technical_candle_geometry(mixed $open, mixed $high, mixed $low, mixed $close): array
    {
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $open = (string) $open;
        $high = (string) $high;
        $low = (string) $low;
        $close = (string) $close;
        $range = bcsub($high, $low, $scale);

        if (bccomp($range, '0', $scale) === 0) {
            return [
                'body' => bcadd('0', '0', $scale),
                'upper_wick' => bcadd('0', '0', $scale),
                'lower_wick' => bcadd('0', '0', $scale),
            ];
        }

        $upperBody = $this->bcmax($open, $close);
        $lowerBody = $this->bcmin($open, $close);

        return [
            'body' => bcdiv($this->bcabs(bcsub($close, $open, $scale)), $range, $scale),
            'upper_wick' => bcdiv(bcsub($high, $upperBody, $scale), $range, $scale),
            'lower_wick' => bcdiv(bcsub($lowerBody, $low, $scale), $range, $scale),
        ];
    }

    // Exponential Moving Average
    /** Calculate EMA on an array, continuing from a retained, mature slice boundary. */
    public function ema(array &$tickers, int $period = 2, string $index = 'close'): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('EMA period must be greater than zero.');
        }
        $key = 'ema('.$period.','.$index.')';
        if ($period === 1) {
            $this->clone_key($tickers, $index, $key);

            return $key;
        }
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $seed = $this->ticker_seed($tickers, [$key], $period);
        $count = $seed === null ? 0 : $seed['__ticker_position'] - 1;
        $previous = '0';
        $sum = '0';
        $alpha = bcdiv('2', bcadd((string) $period, '1', $scale), $scale);
        foreach ($tickers as &$ticker) {
            $count++;
            if ($seed !== null && $this->ticker_cached($ticker, $key)) {
                $previous = (string) $ticker[$key];

                continue;
            }
            $value = $this->bcconv($ticker[$index]);
            if ($count <= $period) {
                $sum = bcadd($sum, $value, $scale);
                $ticker[$key] = bcdiv($sum, (string) $count, $scale);
            } else {
                $delta = bcsub($value, $previous, $scale);
                $ticker[$key] = bcadd($previous, bcmul($alpha, $delta, $scale), $scale);
            }
            $previous = $ticker[$key];
        }
        unset($ticker);

        return $key;
    }

    public function tp(array &$tickers): string
    {
        $key = 'tp()';
        foreach ($tickers as &$ticker) {
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = $this->technical_typical_price_value($ticker['high'], $ticker['low'], $ticker['close']);
            }
        }
        unset($ticker);

        return $key;
    }

    public function tr(array &$tickers): string
    {
        $key = 'tr()';
        $this->ticker_seed($tickers, [$key], 1);
        $previousClose = null;
        foreach ($tickers as &$ticker) {
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = $this->technical_true_range_value($ticker['high'], $ticker['low'], $previousClose);
            }
            $previousClose = $ticker['close'];
        }
        unset($ticker);

        return $key;
    }

    public function min_max(&$tickers, $period = 30, $index = 'close', $decimals = EXCHANGE_ROUND_DECIMALS)
    {
        global $debug;

        if ($debug) {
            echo "function min_max(tickers, $period = 30, $index = 'close', $decimals = EXCHANGE_ROUND_DECIMALS)".PHP_EOL;
        }

        // $c = round(count($tickers)/2, 0);
        $minkey = 'min('.$period.','.$index.','.$decimals.')';
        $maxkey = 'max('.$period.','.$index.','.$decimals.')';
        $absminkey = 'absmin('.$index.','.$decimals.')';
        $absmaxkey = 'absmax('.$index.','.$decimals.')';

        $stepsminkey = 'steps('.$minkey.')';
        $stepsmaxkey = 'steps('.$maxkey.')';
        $absstepsminkey = 'abssteps('.$absminkey.')';
        $absstepsmaxkey = 'abssteps('.$absmaxkey.')';
        $t = end($tickers);
        if (! array_key_exists($minkey, $t) or ! array_key_exists($maxkey, $t) or ! array_key_exists($stepsminkey, $t) or ! array_key_exists($stepsmaxkey, $t) or ! array_key_exists($absminkey, $t) or ! array_key_exists($absmaxkey, $t) or ! array_key_exists($absstepsminkey, $t) or ! array_key_exists($absstepsmaxkey, $t)) {
            $i = 0;
            $buffer = [];
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                array_unshift($buffer, $h);     // First element is the most recent

                if ($i == 0) {
                    $h[$maxkey] = $h[$index];               // You are always the max
                    $h[$minkey] = $h[$index];               // You are always the min
                    $h[$absmaxkey] = $h[$index];            // You are always the max
                    $h[$absminkey] = $h[$index];            // You are always the min
                    $h[$stepsmaxkey] = 0;
                    $h[$stepsminkey] = 0;
                    $h[$absstepsmaxkey] = 0;
                    $h[$absstepsminkey] = 0;
                } else {
                    $h[$absmaxkey] = $this->bcmax($p[$absmaxkey], $h[$index]);
                    $h[$absminkey] = $this->bcmin($p[$absminkey], $h[$index]);

                    if (count($buffer) > $period) {
                        array_pop($buffer);
                    }

                    reset($buffer);
                    $h[$maxkey] = current($buffer)[$index];
                    $h[$minkey] = current($buffer)[$index];

                    foreach ($buffer as &$b) {
                        $h[$maxkey] = $this->bcmax($b[$index], $h[$maxkey]);
                        $h[$minkey] = $this->bcmin($b[$index], $h[$minkey]);
                    }

                    // $h[$minkey] = number_format($this->bcmin($h[$index], $p[$minkey]), $decimals, '.', '');
                    // $h[$maxkey] = number_format($this->bcmax($h[$index], $p[$maxkey]), $decimals, '.', '');
                    bcscale(EXCHANGE_ROUND_DECIMALS);

                    $h[$stepsmaxkey] = 0;
                    $h[$stepsminkey] = 0;
                    $h[$absstepsmaxkey] = 0;
                    $h[$absstepsminkey] = 0;

                    // Look for the absolute
                    if (bccomp($h[$index], $h[$maxkey], $decimals) == -1) {
                        $h[$absstepsmaxkey] = $p[$absstepsmaxkey] + 1;
                    }

                    if (bccomp($h[$index], $h[$minkey], $decimals) == 1) {
                        $h[$absstepsminkey] = $p[$absstepsminkey] + 1;
                    }

                    // Look for the relative
                    for ($ii = 1, $found = false; ($ii < count($buffer)); $ii++) {
                        // echo 'MAX '.$ii.' comparing '. $h[$maxkey] . ' vs '.$buffer[$ii][$index].PHP_EOL;
                        if ((bccomp($h[$maxkey], $buffer[$ii][$index], $decimals) == 0) && ! $found) {
                            // echo 'is '.$ii.PHP_EOL;
                            $h[$stepsmaxkey] = $ii;
                            $found = true;
                        }
                    }

                    for ($jj = 1, $found = false; ($jj < count($buffer)); $jj++) {
                        // echo 'MIN '.$jj.' comparing '. $h[$minkey] . ' vs '.$buffer[$jj][$index].PHP_EOL;
                        if ((bccomp($h[$minkey], $buffer[$jj][$index], $decimals) == 0) && ! $found) {
                            // echo 'is '.$jj.PHP_EOL;
                            $h[$stepsminkey] = $jj;
                            $found = true;
                        }
                    }
                }
                $i++;
                $p = $h;
            }
        }
        $keys = [$minkey, $maxkey, $stepsminkey, $stepsmaxkey, $absminkey, $absmaxkey, $absstepsminkey, $absstepsmaxkey];

        return $keys;
    }

    public function chop(&$tickers, $period = 20)
    {
        global $debug;
        if ($debug) {
            echo "chop(tickers, $period = 20)".PHP_EOL;
        }

        $key = 'chop('.$period.')';
        $t = end($tickers);
        if (! array_key_exists($key, $t)) {
            $buffer = [];
            $key_tr = $this->tr($tickers);
            $bclog10_period = $this->bclog10($period);
            [$key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max] = $this->min_max($tickers, $period, 'high', EXCHANGE_ROUND_DECIMALS * 2);
            [$key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max] = $this->min_max($tickers, $period, 'low', EXCHANGE_ROUND_DECIMALS * 2);

            reset($tickers);
            foreach ($tickers as &$h) {
                array_push($buffer, $h[$key_tr]);
                if (count($buffer) > $period) {
                    array_shift($buffer);
                }
                $sum = 0;
                foreach ($buffer as &$b) {
                    $sum = bcadd($sum, $b, EXCHANGE_ROUND_DECIMALS * 2);
                }

                $h[$key] = bcmul(100, bcdiv($this->bclog10(bcdiv($sum, bcsub($h[$key_min_max_high_max], $h[$key_min_max_low_min], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2)), $bclog10_period, EXCHANGE_ROUND_DECIMALS * 2), 2);
            }
        }

        return $key;
    }

    public function percentage(&$tickers, $index1 = 'close', $index2 = 'open')
    {
        global $debug;

        if ($debug) {
            echo "percentage(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'percentage('.$index1.','.$index2.')';

        if (! array_key_exists($key, $t)) {
            $scale = EXCHANGE_ROUND_DECIMALS * 2;
            reset($tickers);
            foreach ($tickers as &$h) {
                $base = (string) $h[$index2];
                $change = $this->technical_relative_change_value($h[$index1], $base);
                $h[$key] = $change === null
                    ? bcadd('0', '0', $scale)
                    : bcmul($change, '100', $scale);
            }
            unset($h);
        }

        return $key;
    }

    public function difference(&$tickers, $index1 = 'close', $index2 = 'open')
    {
        global $debug;

        if ($debug) {
            echo "difference(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'difference('.$index1.','.$index2.')';

        if (! array_key_exists($key, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$key] = bcsub($h[$index1], $h[$index2], EXCHANGE_ROUND_DECIMALS);
            }
        }

        return $key;
    }

    public function numeric_or(&$tickers, $index1 = 'close', $index2 = 'open')
    {
        global $debug;

        if ($debug) {
            echo "numeric_or(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'or('.$index1.', '.$index2.')';

        if (! array_key_exists($key, $t)) {
            $i = 0;
            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$key] = ((int) $h[$index1] | (int) $h[$index2]);
            }
        }

        return $key;
    }

    public function consecutive(&$tickers, $index = 'close', $precision = EXCHANGE_ROUND_DECIMALS, $condition_value = null)
    {
        global $debug;

        if ($debug) {
            echo "consecutive(tickers, $index = 'close', $precision = EXCHANGE_ROUND_DECIMALS)".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'consecutive('.$index.')';

        if (! array_key_exists($key, $t)) {
            $i = 0;
            reset($tickers);
            foreach ($tickers as &$h) {
                if ($i == 0) {
                    $i = 1;
                    $h[$key] = 0;
                } else {
                    if (is_numeric($h[$index])) {
                        if (bccomp($p[$index], $h[$index], $precision) == 0) {
                            if (is_null($condition_value) || ($h[$index] == $condition_value)) {
                                $h[$key] = $p[$key] + 1;
                            } else {
                                $h[$key] = 0;
                            }
                        } else {
                            $h[$key] = 0;
                        }
                    } else {
                        if (strcasecmp($p[$index], $h[$index]) == 0) {
                            if (is_null($condition_value) || strcasecmp($h[$index], $condition_value) == 0) {
                                $h[$key] = $p[$key] + 1;
                            } else {
                                $h[$key] = 0;
                            }
                        } else {
                            $h[$key] = 0;
                        }
                    }
                }
                $p = $h;
            }
        }

        return $key;
    }

    public function delayed(array &$tickers, int $period = 26, string $index = 'close'): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('Delay must be positive.');
        }
        $this->ticker_require_history($tickers, $period);
        $key = 'delayed('.$period.','.$index.')';
        $buffer = [];
        $first = null;
        foreach ($tickers as &$ticker) {
            $first ??= $ticker[$index];
            $buffer[] = $ticker[$index];
            $value = count($buffer) > $period ? array_shift($buffer) : $first;
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = $value;
            }
        }
        unset($ticker);

        return $key;
    }

    public function rename_key(&$tickers, $oldkey, $newkey)
    {
        global $debug;

        if ($debug) {
            echo "rename_key(tickers, $oldkey, $newkey)".PHP_EOL;
        }
        reset($tickers);
        foreach ($tickers as &$h) {
            $h[$newkey] = $h[$oldkey];
            unset($h[$oldkey]);
        }

        return $tickers;
    }

    public function ichimoku(&$tickers, $tenkansen = 9, $kijunsen = 26, $chikou = 26, $senkou_b = 52)
    {
        global $debug;
        $positions = array_keys($tickers);

        if ($debug) {
            echo "ichimoku (tickers, $tenkansen = 9, $kijunsen = 26, $chikou = 26, $senkou_b = 52)".PHP_EOL;
        }

        $key_tenkansen = 'tenkansen('.$tenkansen.')';
        $key_kijunsen = 'kijunsen('.$kijunsen.')';
        $key_chikou = 'chikou('.$chikou.')';
        $key_senkou_a = 'senkou_a()';
        $key_senkou_b = 'senkou_b('.$senkou_b.')';
        $t = end($tickers);

        if (! array_key_exists($key_tenkansen, $t) || ! array_key_exists($key_kijunsen, $t) || ! array_key_exists($key_chikou, $t) || ! array_key_exists($key_senkou_a, $t) || ! array_key_exists($key_senkou_b, $t)) {
            [$key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max] = $this->min_max($tickers, $tenkansen, 'high', EXCHANGE_ROUND_DECIMALS);
            [$key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max] = $this->min_max($tickers, $tenkansen, 'low', EXCHANGE_ROUND_DECIMALS);

            [$key_min_max_high_min2, $key_min_max_high_max2, $key_min_max_high_steps_min2, $key_min_max_high_steps_max2, $key_abs_min_max_high_min2, $key_abs_min_max_high_max2, $key_abs_min_max_high_steps_min2, $key_abs_min_max_high_steps_max2] = $this->min_max($tickers, $kijunsen, 'high', EXCHANGE_ROUND_DECIMALS);
            [$key_min_max_low_min2, $key_min_max_low_max2, $key_min_max_low_steps_min2, $key_min_max_low_steps_max2, $key_abs_min_max_low_min2, $key_abs_min_max_low_max2, $key_abs_min_max_low_steps_min2, $key_abs_min_max_low_steps_max2] = $this->min_max($tickers, $kijunsen, 'low', EXCHANGE_ROUND_DECIMALS);

            [$key_min_max_high_min3, $key_min_max_high_max3, $key_min_max_high_steps_min3, $key_min_max_high_steps_max3, $key_abs_min_max_high_min3, $key_abs_min_max_high_max3, $key_abs_min_max_high_steps_min3, $key_abs_min_max_high_steps_max3] = $this->min_max($tickers, $senkou_b, 'high', EXCHANGE_ROUND_DECIMALS);
            [$key_min_max_low_min3, $key_min_max_low_max3, $key_min_max_low_steps_min3, $key_min_max_low_steps_max3, $key_abs_min_max_low_min3, $key_abs_min_max_low_max3, $key_abs_min_max_low_steps_min3, $key_abs_min_max_low_steps_max3] = $this->min_max($tickers, $senkou_b, 'low', EXCHANGE_ROUND_DECIMALS);

            $key_delayed = $this->delayed($tickers, $kijunsen, 'close');
            $this->rename_key($tickers, $key_delayed, $key_chikou);
            /*
                Chikou implementation is different; instead of looking into the future, it looks into the past.
                This helps the last index look for the value from the past.
            */

            reset($tickers);
            $i = 0;
            foreach ($tickers as &$h) {
                $k = $i - 26;
                $h[$key_tenkansen] = bcdiv(bcadd($h[$key_min_max_high_max], $h[$key_min_max_low_min]), 2, 8);
                $h[$key_kijunsen] = bcdiv(bcadd($h[$key_min_max_high_max2], $h[$key_min_max_low_min2]), 2, 8);
                if ($i >= 26) {
                    $h[$key_senkou_a] = bcdiv(bcadd($tickers[$positions[$k]][$key_tenkansen], $tickers[$positions[$k]][$key_kijunsen]), 2, 8);
                    $h[$key_senkou_b] = bcdiv(bcadd($tickers[$positions[$k]][$key_min_max_high_max3], $tickers[$positions[$k]][$key_min_max_low_min3]), 2, 8);
                } else {
                    $h[$key_senkou_a] = bcdiv(bcadd($h[$key_tenkansen], $h[$key_kijunsen]), 2, 8);
                    $h[$key_senkou_b] = bcdiv(bcadd($h[$key_min_max_high_max3], $h[$key_min_max_low_min3]), 2, 8);
                }
                $i++;
            }
        }

        $keys = [$key_tenkansen, $key_kijunsen, $key_chikou, $key_senkou_a, $key_senkou_b];

        return $keys;
    }

    // Simple Moving Average
    /** Rolling sum stays exact; truncate only the published SMA to its defined scale. */
    public function sma(array &$tickers, int $period = 20, string $index = 'close'): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('SMA period must be greater than zero.');
        }
        $this->ticker_require_history($tickers, $period - 1);
        $key = 'sma('.$period.','.$index.')';
        $scale = EXCHANGE_ROUND_DECIMALS;
        $sumScale = $scale * 2;
        $buffer = [];
        $position = 0;
        $sum = '0';
        foreach ($tickers as &$ticker) {
            $value = $this->bcconv($ticker[$index]);
            $sumScale = max($sumScale, $this->bcdec($value));
            if (count($buffer) === $period) {
                $sum = bcsub($sum, $buffer[$position], $sumScale);
            }
            $buffer[$position] = $value;
            $position = ($position + 1) % $period;
            $sum = bcadd($sum, $value, $sumScale);
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = bcdiv($sum, (string) count($buffer), $scale);
            }
        }
        unset($ticker);

        return $key;
    }

    public function sto(&$tickers, $period1 = 14, $period2 = 3, $period3 = 3)
    {
        global $debug;

        if ($debug) {
            echo "sto(tickers, $period1 = 14, $period2 = 3, $period3 = 3)".PHP_EOL;
        }

        $key_fastk = '%k_price('.$period1.')';
        $key_dk = '%dk_price('.$period1.','.$period2.')';
        $key_slowd = '%d_price('.$period1.','.$period2.','.$period3.')';
        $t = end($tickers);
        if (! array_key_exists($key_fastk, $t) or ! array_key_exists($key_dk, $t) or ! array_key_exists($key_slowd, $t)) {
            [$key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max] = $this->min_max($tickers, $period1, 'high', EXCHANGE_ROUND_DECIMALS);
            [$key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max] = $this->min_max($tickers, $period1, 'low', EXCHANGE_ROUND_DECIMALS);

            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                if ($h[$key_min_max_high_max] == $h[$key_min_max_low_min]) {
                    $h[$key_fastk] = 100;
                } else {
                    $h[$key_fastk] = bcmul(
                        100,
                        bcdiv(
                            bcsub(
                                $h['close'],
                                $h[$key_min_max_low_min],
                                EXCHANGE_ROUND_DECIMALS),
                            bcsub(
                                $h[$key_min_max_high_max],
                                $h[$key_min_max_low_min],
                                EXCHANGE_ROUND_DECIMALS),
                            EXCHANGE_ROUND_DECIMALS),
                        2);
                }
            }
            $t = $this->sma($tickers, $period2, $key_fastk);
            $this->rename_key($tickers, $t, $key_dk);
            $s = $this->sma($tickers, $period3, $key_dk);
            $this->rename_key($tickers, $s, $key_slowd);
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$key_dk] = number_format($h[$key_dk], 2, '.', '');
                $h[$key_slowd] = number_format($h[$key_slowd], 2, '.', '');
            }
        }

        $keys = [$key_fastk, $key_dk, $key_slowd];

        return $keys;
    }

    // Stochastic RSI
    public function sto_rsi(array &$tickers, int $period1 = 14, int $period2 = 3, int $period3 = 3): array
    {
        if ($period1 < 1 || $period2 < 1 || $period3 < 1) {
            throw new \InvalidArgumentException('Stochastic RSI periods must be positive.');
        }
        $this->ticker_require_history($tickers, $period1 - 1);
        $rsiKey = $this->rsi($tickers, $period1);
        $fast = '%k('.$period1.')';
        $smooth = '%dk('.$period1.','.$period2.')';
        $slow = '%d('.$period1.','.$period2.','.$period3.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $buffer = [];
        $count = 0;
        foreach ($tickers as &$ticker) {
            $count++;
            $position = $ticker['__ticker_position'] ?? $count;
            if ($position > $period1) {
                $buffer[] = $ticker[$rsiKey];
                if (count($buffer) > $period1) {
                    array_shift($buffer);
                }
            }
            if ($this->ticker_cached($ticker, $fast)) {
                continue;
            }
            if (count($buffer) < $period1) {
                $ticker[$fast] = bcadd('50', '0', $scale);

                continue;
            }
            $min = $this->bcmin(...$buffer);
            $max = $this->bcmax(...$buffer);
            $span = bcsub($max, $min, $scale);
            $ticker[$fast] = bccomp($span, '0', $scale) === 0
                ? bcadd('50', '0', $scale)
                : bcmul('100', bcdiv(bcsub($ticker[$rsiKey], $min, $scale), $span, $scale), $scale);
        }
        unset($ticker);
        $source = $this->sma($tickers, $period2, $fast);
        $this->clone_key($tickers, $source, $smooth);
        $source = $this->sma($tickers, $period3, $smooth);
        $this->clone_key($tickers, $source, $slow);

        return [$fast, $smooth, $slow];
    }

    public function compare(
        array &$tickers,
        string $index = 'close',
        string $compare = 'open',
        int $digits = EXCHANGE_ROUND_DECIMALS * 2
    ): string {
        global $debug;

        if ($debug) {
            echo "compare(tickers, $index = 'close', $compare = 'open', $digits = (EXCHANGE_ROUND_DECIMALS * 2))".PHP_EOL;
        }

        $key = 'compare('.$index.','.$compare.')';
        foreach ($tickers as &$ticker) {
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = bccomp(
                    $this->bcconv($ticker[$index]),
                    $this->bcconv($ticker[$compare]),
                    $digits
                );
            }
        }
        unset($ticker);

        return $key;
    }

    public function slope(array &$tickers, string $index = 'close', int $offset = 1): array
    {
        if ($offset < 1) {
            throw new \InvalidArgumentException('Slope offset must be positive.');
        }
        $this->ticker_require_history($tickers, $offset);
        $key = 'slope('.$index.','.$offset.')';
        $sign = 'slope_sign('.$index.','.$offset.')';
        $positions = array_keys($tickers);
        $i = 0;
        foreach ($tickers as &$ticker) {
            if (! $this->ticker_cached($ticker, $key)) {
                $previous = $i >= $offset ? $tickers[$positions[$i - $offset]][$index] : $ticker[$index];
                $ticker[$key] = bcdiv(bcsub($ticker[$index], $previous, EXCHANGE_ROUND_DECIMALS), (string) $offset, EXCHANGE_ROUND_DECIMALS);
                $ticker[$sign] = bccomp($ticker[$key], '0', EXCHANGE_ROUND_DECIMALS);
            }
            $i++;
        }
        unset($ticker);

        return [$key, $sign];
    }

    public function inflexion(&$tickers, $keyA = 'close', $keyB = 'ema(2,close)')
    {
        global $debug;
        $positions = array_keys($tickers);
        if ($debug) {
            echo "inflexion(tickers, $keyA = 'close',$keyB = 'ema(2,close)')".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'inflexion('.$keyA.','.$keyB.')';

        if (array_key_exists($keyA, $t) && array_key_exists($keyB, $t)) {
            $key = 'inflexion('.$keyA.','.$keyB.')';

            if (! array_key_exists($key, $t)) {
                [$key_fastk, $key_dk, $key_slowd] = $this->sto($tickers, 14, 3, 3);
                $i = 0;
                $key_compare = $this->compare($tickers, $keyA, $keyB, EXCHANGE_ROUND_DECIMALS);
                $key_consecutive_compare = $this->consecutive($tickers, $key_compare);
                [$key_tenkansen, $key_kijunsen, $key_chikou, $key_senkou_a, $key_senkou_b] = $this->ichimoku($tickers);
                [$key_kijunsen_slope, $key_kijunsen_slope_sign] = $this->slope($tickers, $key_kijunsen, 1);
                reset($tickers);
                foreach ($tickers as &$h) {
                    $h[$key] = 0;
                    if ($h[$key_consecutive_compare] == 0) { // Inflexion point at $i - 1
                        if ($i) {
                            $k = $i - 1;
                            $fast_test = bcdiv(bcadd($tickers[$positions[$k]][$key_fastk], $tickers[$positions[$k]][$key_dk], EXCHANGE_ROUND_DECIMALS * 2), '2', EXCHANGE_ROUND_DECIMALS * 2);
                            $slow_test = bcdiv(bcadd($tickers[$positions[$k]][$key_dk], $tickers[$positions[$k]][$key_slowd], EXCHANGE_ROUND_DECIMALS * 2), '2', EXCHANGE_ROUND_DECIMALS * 2);
                            $k_compare = $tickers[$positions[$k]][$key_compare];
                            if (((bccomp($fast_test, '80', EXCHANGE_ROUND_DECIMALS * 2) >= 0) && ($k_compare == 1)) ||
                                ((bccomp($fast_test, '20', EXCHANGE_ROUND_DECIMALS * 2) <= 0) && ($k_compare == -1)) ||
                                ((bccomp($slow_test, '70', EXCHANGE_ROUND_DECIMALS * 2) >= 0) && ($k_compare == 1)) ||
                                ((bccomp($slow_test, '30', EXCHANGE_ROUND_DECIMALS * 2) <= 0) && ($k_compare == -1))
                            ) {
                                $tickers[$positions[$k]][$key] = 1;
                            }
                        }
                    }
                    $i++;
                }

                foreach ($tickers as &$h) {
                    if ($h[$key_kijunsen_slope_sign] == 0) {
                        $h[$key] = 0;
                    }
                }
            }
        }

        return $key;
    }

    // Mean Deviation
    public function md(array &$tickers, int $period, string $key1, string $key2): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('Mean deviation period must be positive.');
        }
        $this->ticker_require_history($tickers, $period - 1);
        $key = "md($period, $key1, $key2)";
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $buffer = [];
        foreach ($tickers as &$ticker) {
            $buffer[] = $ticker[$key1];
            if (count($buffer) > $period) {
                array_shift($buffer);
            }
            if ($this->ticker_cached($ticker, $key)) {
                continue;
            }
            $sum = '0';
            foreach ($buffer as $value) {
                $sum = bcadd($sum, $this->bcabs(bcsub($value, $ticker[$key2], $scale)), $scale);
            }
            $ticker[$key] = bcdiv($sum, (string) count($buffer), $scale);
        }
        unset($ticker);

        return $key;
    }

    // Commodity Channel Index
    public function cci(array &$tickers, int $period = 20): string
    {
        $tp = $this->tp($tickers);
        $average = $this->sma($tickers, $period, $tp);
        $deviation = $this->md($tickers, $period, $tp, $average);
        $key = 'cci('.$period.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        foreach ($tickers as &$ticker) {
            if ($this->ticker_cached($ticker, $key)) {
                continue;
            }
            $denominator = bcmul($ticker[$deviation], '0.015', $scale);
            $ticker[$key] = bccomp($denominator, '0', $scale) === 0
                ? '0'
                : bcdiv(bcsub($ticker[$tp], $ticker[$average], $scale), $denominator, $scale);
        }
        unset($ticker);

        return $key;
    }

    // DM+/-
    public function dm(&$tickers)
    {
        global $debug;
        if ($debug) {
            echo 'dm(tickers)'.PHP_EOL;
        }

        $mkey = '-dm()';
        $pkey = '+dm()';
        $t = end($tickers);

        if (! array_key_exists($mkey, $t) or ! array_key_exists($pkey, $t)) {
            $i = 1;
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                if ($i == 1) {
                    $h[$pkey] = $h['close'];
                    $h[$mkey] = $h['close'];
                } else {
                    $upmove = bcsub($h['high'], $p['high'], EXCHANGE_ROUND_DECIMALS);
                    $downmove = bcsub($p['low'], $h['low'], EXCHANGE_ROUND_DECIMALS);
                    $h[$pkey] = 0;
                    $h[$mkey] = 0;
                    if ((bccomp($upmove, $downmove, EXCHANGE_ROUND_DECIMALS * 2) > 0) and (bccomp($upmove, 0, EXCHANGE_ROUND_DECIMALS * 2) > 0)) {
                        $h[$pkey] = $upmove;
                    }

                    if ((bccomp($downmove, $upmove, EXCHANGE_ROUND_DECIMALS * 2) > 0) and (bccomp($downmove, 0, EXCHANGE_ROUND_DECIMALS * 2) > 0)) {
                        $h[$mkey] = $downmove;
                    }
                }
                $p = $h;
                $i++;
            }
        }

        $keys = [$mkey, $pkey];

        return $keys;
    }

    public function gain(array &$tickers, int $period = 14): array
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('Gain period must be greater than zero.');
        }
        $gainKey = 'average_gain('.$period.')';
        $lossKey = 'average_loss('.$period.')';
        $deltaKey = 'delta('.$period.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $seed = $this->ticker_seed($tickers, [$gainKey, $lossKey], $period + 1);
        $changes = $seed === null ? 0 : $seed['__ticker_position'] - 2;
        $previousClose = null;
        $averageGain = $averageLoss = $gainSum = $lossSum = '0';
        $weight = bcsub((string) $period, '1', 0);
        foreach ($tickers as &$ticker) {
            $close = $this->bcconv($ticker['close']);
            if ($seed !== null && $this->ticker_cached($ticker, $gainKey) && $this->ticker_cached($ticker, $lossKey)) {
                $changes++;
                $averageGain = (string) $ticker[$gainKey];
                $averageLoss = (string) $ticker[$lossKey];
                $previousClose = $close;

                continue;
            }
            if ($previousClose === null) {
                $ticker['gain'] = $ticker['loss'] = $ticker[$deltaKey] = bcadd('0', '0', $scale);
                $ticker[$gainKey] = $ticker[$lossKey] = bcadd('0', '0', $scale);
            } else {
                $delta = bcsub($close, $previousClose, $scale);
                $ticker[$deltaKey] = $delta;
                $ticker['gain'] = bccomp($delta, '0', $scale) > 0 ? $delta : bcadd('0', '0', $scale);
                $ticker['loss'] = bccomp($delta, '0', $scale) < 0 ? $this->bcabs($delta) : bcadd('0', '0', $scale);
                $changes++;
                if ($changes <= $period) {
                    $gainSum = bcadd($gainSum, $ticker['gain'], $scale);
                    $lossSum = bcadd($lossSum, $ticker['loss'], $scale);
                    $ticker[$gainKey] = bcdiv($gainSum, (string) $changes, $scale);
                    $ticker[$lossKey] = bcdiv($lossSum, (string) $changes, $scale);
                } else {
                    $ticker[$gainKey] = bcdiv(bcadd(bcmul($averageGain, $weight, $scale), $ticker['gain'], $scale), (string) $period, $scale);
                    $ticker[$lossKey] = bcdiv(bcadd(bcmul($averageLoss, $weight, $scale), $ticker['loss'], $scale), (string) $period, $scale);
                }
            }
            $averageGain = $ticker[$gainKey];
            $averageLoss = $ticker[$lossKey];
            $previousClose = $close;
        }
        unset($ticker);

        return ['gain', 'loss', $lossKey, $gainKey, $deltaKey];
    }

    // RSI
    public function rsi(array &$tickers, int $period = 14): string
    {
        [, , $lossKey, $gainKey] = $this->gain($tickers, $period);
        $key = 'rsi('.$period.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        foreach ($tickers as &$ticker) {
            if ($this->ticker_cached($ticker, $key)) {
                continue;
            }
            if (bccomp($ticker[$lossKey], '0', $scale) > 0) {
                $rs = bcdiv($ticker[$gainKey], $ticker[$lossKey], $scale);
                $ticker[$key] = bcsub('100', bcdiv('100', bcadd('1', $rs, $scale), $scale), $scale);
            } elseif (bccomp($ticker[$gainKey], '0', $scale) === 0) {
                $ticker[$key] = bcadd('50', '0', $scale);
            } else {
                $ticker[$key] = bcadd('100', '0', $scale);
            }
        }
        unset($ticker);

        return $key;
    }

    public function roc(array &$tickers, int $delay = 1, string $index = 'close'): string
    {
        $past = $this->delayed($tickers, $delay, $index);
        $key = 'roc('.$delay.','.$index.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $count = 0;
        foreach ($tickers as &$ticker) {
            $count++;
            if ($this->ticker_cached($ticker, $key)) {
                continue;
            }
            $change = $count <= $delay ? null : $this->technical_relative_change_value($ticker[$index], $ticker[$past]);
            $ticker[$key] = $change === null ? bcadd('0', '0', $scale) : bcmul($change, '100', $scale);
        }
        unset($ticker);

        return $key;
    }

    public function macd(&$tickers, $short_period = 12, $long_period = 26, $signal_period = 9)
    {
        global $debug;

        if ($debug) {
            echo "macd(tickers, $short_period = 12, $long_period = 26, $signal_period = 9)".PHP_EOL;
        }

        $t = end($tickers);
        $macdkey = 'macd('.$short_period.','.$long_period.','.$signal_period.')';
        $sigkey = 'macd_signal('.$macdkey.')';
        if (! array_key_exists($macdkey, $t)) {
            $skey = $this->ema($tickers, $short_period, 'close');
            $lkey = $this->ema($tickers, $long_period, 'close');

            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$macdkey] = bcsub($h[$skey], $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        $emasigkey = $this->ema($tickers, $signal_period, $macdkey);
        $this->rename_key($tickers, $emasigkey, $sigkey);

        $dkey = 'delta_macd('.$macdkey.','.$sigkey.')';
        if (! array_key_exists($dkey, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$dkey] = bcsub($h[$macdkey], $h[$sigkey], EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        $keys = [$macdkey, $sigkey, $dkey];

        return $keys;
    }

    // Percentage Price Oscillator
    public function ppo(&$tickers, $short_period = 12, $long_period = 26, $signal_period = 9)
    {
        global $debug;

        if ($debug) {
            echo "ppo(tickers, $short_period = 12, $long_period = 26, $signal_period = 9)".PHP_EOL;
        }

        $t = end($tickers);
        $ppokey = 'ppo('.$short_period.','.$long_period.','.$signal_period.')';
        $sigkey = 'ppo_signal('.$ppokey.')';
        if (! array_key_exists($ppokey, $t)) {
            $skey = $this->ema($tickers, $short_period, 'close');
            $lkey = $this->ema($tickers, $long_period, 'close');

            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$ppokey] = bcmul(bcdiv(bcsub($h[$skey], $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2), $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2), 100, 2);
            }
        }

        $emasigkey = $this->ema($tickers, $signal_period, $ppokey);
        $this->rename_key($tickers, $emasigkey, $sigkey);

        $dkey = 'delta_ppo('.$ppokey.','.$sigkey.')';
        if (! array_key_exists($dkey, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$dkey] = bcsub($h[$ppokey], $h[$sigkey], EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        $keys = [$ppokey, $sigkey, $dkey];

        return $keys;
    }

    public function atr(array &$tickers, int $period = 14, string $average_function = 'smma'): string
    {
        if ($period < 1 || ! in_array(strtolower($average_function), ['sma', 'ema', 'smma'], true)) {
            throw new \InvalidArgumentException('ATR requires a positive period and sma, ema or smma averaging.');
        }
        $average_function = strtolower($average_function);
        $key = 'atr('.$period.($average_function === 'smma' ? '' : ','.$average_function).')';
        $tr = $this->tr($tickers);
        $source = $this->{$average_function}($tickers, $period, $tr);
        // Keep the averaging column: it is the exact recursive boundary seed.
        $this->clone_key($tickers, $source, $key);

        return $key;
    }

    public function atrp(array &$tickers, int $period = 14, string $average_function = 'smma'): string
    {
        $average_function = strtolower($average_function);
        $atr = $this->atr($tickers, $period, $average_function);
        $key = 'atrp('.$period.($average_function === 'smma' ? '' : ','.$average_function).')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        foreach ($tickers as &$ticker) {
            if ($this->ticker_cached($ticker, $key)) {
                continue;
            }
            $close = $this->bcconv($ticker['close']);
            if (bccomp($close, '0', $scale) === 0) {
                $close = $this->bcpow10(-EXCHANGE_ROUND_DECIMALS, $scale);
            }
            $ticker[$key] = bcmul(bcdiv($ticker[$atr], $close, $scale), '100', $scale);
        }
        unset($ticker);

        return $key;
    }

    // Smoothed Moving Average
    /** Wilder average on an array: full-period SMA seed, then one smoothing pass. */
    public function smma(array &$tickers, int $period = 20, string $index = 'close'): string
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('SMMA period must be greater than zero.');
        }
        $key = 'smma('.$period.','.$index.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        $seed = $this->ticker_seed($tickers, [$key], $period);
        $count = $seed === null ? 0 : $seed['__ticker_position'] - 1;
        $sum = $previous = '0';
        $weight = bcsub((string) $period, '1', 0);
        foreach ($tickers as &$ticker) {
            $count++;
            if ($seed !== null && $this->ticker_cached($ticker, $key)) {
                $previous = (string) $ticker[$key];

                continue;
            }
            $value = $this->bcconv($ticker[$index]);
            if ($count <= $period) {
                $sum = bcadd($sum, $value, $scale);
                $ticker[$key] = bcdiv($sum, (string) $count, $scale);
            } else {
                $ticker[$key] = bcdiv(bcadd(bcmul($previous, $weight, $scale), $value, $scale), (string) $period, $scale);
            }
            $previous = $ticker[$key];
        }
        unset($ticker);

        return $key;
    }

    public function adx(&$tickers, $period = 14)
    {
        global $debug;
        if ($debug) {
            echo "adx(tickers, $period = 14)".PHP_EOL;
        }

        $adx_key = 'adx('.$period.')';
        $dx_key = 'dx()';
        $pkey = '+di('.$period.')';
        $mkey = '-di('.$period.')';
        $t = end($tickers);
        if (! array_key_exists($mkey, $t) or ! array_key_exists($pkey, $t) or ! array_key_exists($adx_key, $t) or ! array_key_exists($dx_key, $t)) {
            $tr_key = $this->tr($tickers);
            [$minus_dm_key, $plus_dm_key] = $this->dm($tickers);
            $sma_dmp_key = $this->sma($tickers, $period, $plus_dm_key);
            $sma_dmm_key = $this->sma($tickers, $period, $minus_dm_key);
            $sma_tr_key = $this->sma($tickers, $period, $tr_key);
            $tpkey = "t$pkey";
            $tmkey = "t$mkey";

            reset($tickers);
            foreach ($tickers as &$h) {
                $tp = $this->bcabs(bcdiv($h[$sma_dmp_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
                $tm = $this->bcabs(bcdiv($h[$sma_dmm_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
                $h[$pkey] = bcmul(100, $tp, 2);
                $h[$mkey] = bcmul(100, $tm, 2);

                $sub = bcsub($h[$pkey], $h[$mkey], EXCHANGE_ROUND_DECIMALS * 2);
                $add = bcadd($h[$pkey], $h[$mkey], EXCHANGE_ROUND_DECIMALS * 2);
                if (bccomp($add, 0, EXCHANGE_ROUND_DECIMALS * 2)) {
                    $h[$dx_key] = bcmul(100, $this->bcabs(bcdiv($sub, $add, EXCHANGE_ROUND_DECIMALS * 2)), 2);
                } else {
                    $h[$dx_key] = 0;
                }
            }
            $ttkey = $this->smma($tickers, $period, $dx_key); // $ttkey = 'ema('.$period.','.$tkey.')';
            $this->rename_key($tickers, $ttkey, $adx_key);

            reset($tickers);
            foreach ($tickers as &$h) {
                $h[$adx_key] = number_format($h[$adx_key], 2, '.', '');
            }
        }

        $keys = [$adx_key, $dx_key, $mkey, $pkey];

        return $keys;
    }

    public function midkey(&$tickers, $key1 = 'high', $key2 = 'low')
    {
        global $debug;
        if ($debug) {
            echo "midkey(tickers, $key1 = 'high', $key2 = 'low')".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'average('.$key1.','.$key2.')';
        if (! array_key_exists($key, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $sum = bcadd($h[$key1], $h[$key2], EXCHANGE_ROUND_DECIMALS);
                $h[$key] = bcdiv($sum, 2, EXCHANGE_ROUND_DECIMALS);
                if ($debug) {
                    echo "midkey($key1, $key2) = (".$h[$key1].' + '.$h[$key2].")/2 = $sum/2 = ".$h[$key].PHP_EOL;
                }
            }
        }

        return $key;
    }

    // Awesome Oscillator
    public function ao(&$tickers, $short = 5, $long = 34)
    {
        global $debug;
        if ($debug) {
            echo "ao(tickers, $short = 5, $long = 34)".PHP_EOL;
        }

        $t = end($tickers);
        $key = 'ao('.$short.','.$long.')';
        $key_color = 'ao_color('.$short.','.$long.')';
        $midkey = $this->midkey($tickers);
        if (! array_key_exists($key, $t) or ! array_key_exists($midkey, $t) or ! array_key_exists($key_color, $t)) {
            $sma_short_key = $this->sma($tickers, $short, $midkey);
            $sma_long_key = $this->sma($tickers, $long, $midkey);

            $i = 1;
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$key] = bcsub($h[$sma_short_key], $h[$sma_long_key], EXCHANGE_ROUND_DECIMALS * 2);
                if ($i == 1) {
                    $h[$key_color] = 'green';
                } else {
                    if (bccomp($h[$key], $p[$key], EXCHANGE_ROUND_DECIMALS * 2) > -1) {
                        $h[$key_color] = 'green';
                    } else {
                        $h[$key_color] = 'red';
                    }
                }
                $p = $h;
                $i++;
            }
        }

        $keys = [$key, $key_color, $midkey];

        return $keys;
    }

    // Accelerator
    public function ac(&$tickers, $period = 5)
    {
        global $debug;
        if ($debug) {
            echo "ac(tickers, $period = 5)";
        }

        $key = 'ac('.$period.')';
        $t = end($tickers);

        if (! array_key_exists($key, $t)) {
            [$key_ao, $key_ao_color, $midkey] = $this->ao($tickers, $period, 34);
            $smakey = $this->sma($tickers, $period, $key_ao);
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$key] = bcsub($h[$key_ao], $h[$smakey], EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        return $key;
    }

    // Bollinger Band
    public function bb(&$tickers, $period = 20, $stddev = 2)
    {
        global $debug;
        if ($debug) {
            echo "bb(tickers, $period = 20, $stddev = 2)".PHP_EOL;
        }

        $t = end($tickers);
        $keyhbb = 'bb_high('.$period.','.$stddev.')';
        $keylbb = 'bb_low('.$period.','.$stddev.')';
        $bb_qz = 'bb_bw('.$period.','.$stddev.')';
        $keystd = 'stddev('.$period.')';
        $sma_key = $this->sma($tickers, $period, 'close');
        if (! array_key_exists($keyhbb, $t) or ! array_key_exists($keylbb, $t) or ! array_key_exists($bb_qz, $t) or ! array_key_exists($keystd, $t)) {
            $buffer = [];
            $i = 0;
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                array_push($buffer, $h['close']);
                if (count($buffer) > $period) {
                    array_shift($buffer);
                }
                if (count($buffer) > 1) {
                    $std = $this->stats_standard_deviation($buffer, false);
                    $h[$keystd] = $std;
                    $h[$keyhbb] = bcadd($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
                    $h[$keylbb] = bcsub($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
                    $h[$bb_qz] = bcdiv(bcsub($h[$keyhbb], $h[$keylbb], EXCHANGE_ROUND_DECIMALS * 2), $h[$sma_key], EXCHANGE_ROUND_DECIMALS * 2);
                }
            }
        }

        $keys = [$keylbb, $keyhbb, $bb_qz, $keystd, $sma_key];

        return $keys;
    }

    // Keltner
    public function keltner(&$tickers, $period = 20, $bandwidth = 1.5)
    {
        global $debug;
        if ($debug) {
            echo "keltner(tickers, $period = 20, $bandwidth = 1.5)".PHP_EOL;
        }

        $t = end($tickers);
        $keyhk = 'keltner_high('.$period.','.$bandwidth.')';
        $keylk = 'keltner_low('.$period.','.$bandwidth.')';
        if (! array_key_exists($keyhk, $t) or ! array_key_exists($keylk, $t)) {
            $key_tp = $this->tp($tickers);
            $sma_key = $this->sma($tickers, $period, $key_tp);
            $key_atr = $this->atr($tickers, 10);
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$keyhk] = bcadd($h[$sma_key], bcmul($bandwidth, $h[$key_atr], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
                $h[$keylk] = bcsub($h[$sma_key], bcmul($bandwidth, $h[$key_atr], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        $keys = [$keylk, $keyhk, $sma_key];

        return $keys;
    }

    public function squeeze(&$tickers)
    {
        global $debug;
        if ($debug) {
            echo 'squeeze(tickers)'.PHP_EOL;
        }

        $t = end($tickers);
        $key = 'squeeze()';
        $key_choppy = 'choppy()';
        if (! array_key_exists($key, $t) or ! array_key_exists($key_choppy, $t)) {
            [$key_lbb_20_2, $key_hbb_20_2, $key_bb_qz_20_2, $key_std_20_2, $key_sma_20_2] = $this->bb($tickers, 20, 2);
            [$key_lk, $key_hk, $key_sma_close] = $this->keltner($tickers, 20, 1.5);
            reset($tickers);
            $i = 0;
            foreach ($tickers as &$h) {      // Last element is the most rescent
                if ($i > 1) {
                    $h[$key] = bcsub($h[$key_hbb_20_2], $h[$key_hk], EXCHANGE_ROUND_DECIMALS * 2);
                    if (bccomp($this->bcconv($h[$key]), $this->bcconv('0.0'), EXCHANGE_ROUND_DECIMALS) > 0) {
                        $h[$key_choppy] = 0;
                    } else {
                        $h[$key_choppy] = 1;
                    }
                }
                $i++;
            }
        }

        $keys = [$key, $key_choppy];

        return $keys;
    }

    public function super_trend(&$tickers, $period = 10, $factor = 3)
    {
        global $debug;
        if ($debug) {
            echo "super_trend(tickers, $period = 10, $factor = 3)".PHP_EOL;
        }

        $t = end($tickers);
        $key_super = 'super_trend('.$period.','.$factor.')';
        $key_upper = 'super_trend_upper('.$period.','.$factor.')';
        $key_lower = 'super_trend_lower('.$period.','.$factor.')';
        if (! array_key_exists($key_super, $t) or ! array_key_exists($key_upper, $t) or ! array_key_exists($key_lower, $t)) {
            $key_hl2 = $this->midkey($tickers, 'high', 'low');
            $key_atr = $this->atr($tickers, $period);
            $i = 1;
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$key_upper] = $h[$key_hl2] + $factor * $h[$key_atr];
                $h[$key_lower] = $h[$key_hl2] - $factor * $h[$key_atr];
                $h[$key_super] = $h[$key_upper];
                $note = -1;
                if ($i == 1) {
                    // Keep the current values
                } else {
                    if (($h[$key_lower] > $p[$key_lower]) || ($p['close'] < $p[$key_lower])) {
                        // Keep the value
                    } else {
                        $h[$key_lower] = $p[$key_lower];
                    }

                    if (($h[$key_upper] < $p[$key_upper]) || ($p['close'] > $p[$key_upper])) {
                        // Keep the value
                    } else {
                        $h[$key_upper] = $p[$key_upper];
                    }

                    if ($p[$key_super] == $p[$key_upper]) {
                        $note = ($h['close'] > $h[$key_upper]) ? 1 : -1;
                    } else {
                        $note = ($h['close'] < $h[$key_lower]) ? -1 : 1;
                    }
                }

                $i++;
                $h[$key_super] = ($note == 1) ? $h[$key_lower] : $h[$key_upper];
                $p = $h;
            }
        }
        $keys = [$key_super, $key_upper, $key_lower];

        return $keys;
    }

    public function price_speed(&$tickers, $period = 1, $bar_time_in_seconds = null)
    {
        global $debug;
        if ($debug) {
            echo "price_speed(tickers, $period = 1, $bar_time_in_seconds = null)".PHP_EOL;
        }

        if ((count($tickers) < 2) && (is_null($bar_time_in_seconds))) {
            return null;
        }

        if (count($tickers) > 1) {
            $c = end($tickers);
            $d = prev($tickers);
            if (is_null($c) || is_null($d)) {
                return null;
            }

            $current_timestamp = $c['microtimestamp'] ?? $c[0] ?? null;
            $previous_timestamp = $d['microtimestamp'] ?? $d[0] ?? null;
            if (! is_numeric($current_timestamp) || ! is_numeric($previous_timestamp)) {
                return null;
            }

            $bar_time_in_seconds = abs((int) $current_timestamp - (int) $previous_timestamp) / 1000;
        }

        if (is_null($bar_time_in_seconds) || $bar_time_in_seconds <= 0) {
            return null;
        }

        $t = end($tickers);
        $key = 'speed('.$period.','.$bar_time_in_seconds.')';
        if (! array_key_exists($key, $t)) {
            $last_close = $this->delayed($tickers, $period, 'close');
            reset($tickers);
            $c = 0;
            foreach ($tickers as &$h) {      // Last element is the most recent
                $c++;
                if ($c > $period) {
                    $c = $period;
                }
                $diff = bcsub($this->bcconv($h['close']), $this->bcconv($h[$last_close]), EXCHANGE_ROUND_DECIMALS);
                $time = $c * $bar_time_in_seconds;
                $h[$key] = bcdiv($diff, (string) $time, EXCHANGE_ROUND_DECIMALS);
                if ($debug) {
                    echo "speed($period,$bar_time_in_seconds) = (".$h['close'].' - '.$h[$last_close].") / ($c * $bar_time_in_seconds) = $diff / $time = ".$h[$key].PHP_EOL;
                }
            }
        }

        return $key;
    }

    public function volume_activity(array &$tickers, int $period = 20): string
    {
        $average = $this->sma($tickers, $period, 'volume');
        $key = 'volume_activity('.$period.')';
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        foreach ($tickers as &$ticker) {
            if (! $this->ticker_cached($ticker, $key)) {
                $ticker[$key] = bccomp($ticker[$average], '0', $scale) === 0
                    ? bcadd('0', '0', $scale)
                    : $this->technical_relative_change_value($ticker['volume'], $ticker[$average]);
            }
        }
        unset($ticker);

        return $key;
    }

    public function candle_geometry(array &$tickers): array
    {
        $keys = ['body', 'upper_wick', 'lower_wick', 'direction'];
        $directionKey = $this->compare($tickers, 'close', 'open', EXCHANGE_ROUND_DECIMALS * 2);

        foreach ($tickers as &$ticker) {
            $geometry = $this->technical_candle_geometry($ticker['open'], $ticker['high'], $ticker['low'], $ticker['close']);
            foreach ($geometry as $name => $value) {
                $ticker['candle.'.$name] = $value;
            }
            $ticker['candle.direction'] = $ticker[$directionKey];
        }
        unset($ticker);

        return array_map(static fn (string $name): string => 'candle.'.$name, $keys);
    }
}
