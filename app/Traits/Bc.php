<?php
namespace App\Traits;
if (!defined('EXCHANGE_ROUND_DECIMALS'))
	define('EXCHANGE_ROUND_DECIMALS', 8);

trait Bc
{
	function bcdec(...$numbers){
		$dec = 2;	// At least 2 decimal places
		foreach ($numbers as $number){
			$dec = max(strlen(substr(strrchr(strval($number), "."), 1)), $dec);
		}
		return $dec;
	}

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
    
	function bcabs($number){
		return preg_replace('/^\-+/', '', $number);
	}

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

    function bcpow10(int $n, int $scale = 10): string {
        if ($n === 0) {
            return '1';
        }
    
        if ($n > 0) {
            // For positive integers, use standard bcpow
            return bcpow('10', (string)$n, $scale);
        }
    
        // For negative integers, 10^N is equal to 1 / (10^|N|)
        $positiveExponent = (string)abs($n);
        $denominator = bcpow('10', $positiveExponent, 0); // No decimals needed for denominator
        
        return bcdiv('1', $denominator, $scale);
    }
}
