<?php
if (!function_exists('bcdec')) {
	function bcdec(...$numbers){
		$dec = 2;	// At least 2 decimal places
		foreach ($numbers as $number){
			$dec = max(strlen(substr(strrchr(strval($number), "."), 1)), $dec);
		}
		return $dec;
	}
}


if (!function_exists('bclog10')) {
	function bclog10($n){
		$pos=strpos($n,'.');
		if($pos===false){
			$dec_frac='.'.substr($n,0,15);$pos=strlen($n);
		}
                else{
			 $dec_frac='.'.substr(substr($n,0,$pos).substr($n,$pos+1),0,15);
		}
		return log10((float)$dec_frac)+(float)$pos;
        }
}

if (!function_exists('bcabs')) {
	function bcabs($number){
		return preg_replace('/^\-+/', '', $number);
	}
}

if (!function_exists('bcconv')) {
	function bcconv($fNumber){
		$sAppend = '';
		$iDecimals = ini_get('precision') - floor(log10(abs($fNumber)));
		if (0 > $iDecimals){
			$fNumber *= pow(10, $iDecimals);
			$sAppend = str_repeat('0', -$iDecimals);
			$iDecimals = 0;
		}
		return number_format($fNumber, intval($iDecimals), '.', '').$sAppend;
	}
}

if (!function_exists('bcmax')) {
	function bcmax() {
		$args = func_get_args();
		if (count($args) == 0) return false;
		$max = $args[0];
		foreach($args as $value) {
			if (bccomp($value, $max, EXCHANGE_ROUND_DECIMALS * 2) == 1) {
				$max = $value;
			}
		}
		return $max;
	}
}

if (!function_exists('bcmin')) {
	function bcmin() {
		$args = func_get_args();
		if (count($args) == 0) return false;
		$min = $args[0];
		foreach($args as $value) {
			if (bccomp($min, $value, EXCHANGE_ROUND_DECIMALS * 2) == 1) {
				$min = $value;
			}
		}
		return $min;
	}
}

if (!function_exists('stats_standard_deviation')) {
        /**
                * This user-land implementation follows the implementation quite strictly;
                * it does not attempt to improve the code or algorithm in any way. It will
                * raise a warning if you have fewer than 2 values in your array, just like
                * the extension does (although as an E_USER_WARNING, not E_WARNING).
                *
                * @param array $a
                * @param bool $sample [optional] Defaults to false
                * @return float|bool The standard deviation or false on error.
                */
	function stats_standard_deviation(array $a, $sample = false) {
		$n = count($a);
		if ($n === 0) {
                        trigger_error("The array has zero elements", E_USER_WARNING);
			return false;
		}
		if ($sample && $n === 1) {
			trigger_error("The array has only 1 element", E_USER_WARNING);
			return false;
		}
		// $mean = array_sum($a) / $n;
                $aa = 0.0;
		foreach ($a as $val){
			$aa = bcadd($val, $aa, EXCHANGE_ROUND_DECIMALS * 2);
		}
		$mean = bcdiv($aa, $n, EXCHANGE_ROUND_DECIMALS * 2);
		$carry = 0.0;
		foreach ($a as $val) {
			// $d = ((double) $val) - $mean;
			$d = bcsub($val, $mean, EXCHANGE_ROUND_DECIMALS * 2);
			// $carry += $d * $d;
			$d2 = bcmul($d, $d, EXCHANGE_ROUND_DECIMALS * 2);
			$carry = bcadd($carry, $d2, EXCHANGE_ROUND_DECIMALS * 2);
		};
		if ($sample) {
			--$n;
		}
		// sqrt($carry / $n);
		$div = bcdiv($carry, $n, EXCHANGE_ROUND_DECIMALS * 2);
		return bcsqrt($div, EXCHANGE_ROUND_DECIMALS * 2);
	}
}
