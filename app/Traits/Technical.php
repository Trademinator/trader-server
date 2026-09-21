<?php
namespace App\Traits;
if (!defined('EXCHANGE_ROUND_DECIMALS'))
	define('EXCHANGE_ROUND_DECIMALS', 8);

trait Technical
{

	function delete_key(&$tickers, ...$keys){	// TODO: fix performance
		foreach ($tickers as &$h){
			foreach ($keys as &$key){
				unset ($h[$key]);
			}
		}
	}

	function clone_key(&$tickers, $oldkey, $newkey){
		reset($tickers);
		foreach ($tickers as &$h){
                        $h[$newkey] = $h[$oldkey];
                }
		return $tickers;
	}

	function normalize(&$tikcers, $key = 'close', $index = 'close'){
		global $debug;

		if ($debug){
			echo "normalize(tikcers, $key = 'close', $index = 'close')".PHP_EOL;
		}

		$key = 'normalized('.$key.','.$index.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most recent
				if (bccomp($h[$index], 0, EXCHANGE_ROUND_DECIMALS * 2) > 0){
					$h[$key] = bcdiv($h[$key], $h[$index], EXCHANGE_ROUND_DECIMALS * 2);
				}
				else{
					$h[$key] = 0;
				}
			}
		}

                return $key;
	}

	// Exponential Moving Average
	function ema(&$tickers, $period = 2, $index = 'close'){
		global $debug;

		if ($debug){
			echo "ema(tickers, $period = 2, $index = 'close')".PHP_EOL;
		}

		$key = 'ema('.$period.','.$index.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			if ($period == 1){
				$this->clone_key($tickers, $index, $key);
			}
			else{
				$i = 1;
				reset($tickers);
				foreach ($tickers as &$h){      // Last element is the most recent
					if ($i == 1){
						$h[$key] = number_format($h[$index], EXCHANGE_ROUND_DECIMALS, '.', '');
						if ($debug){
							echo "ema($period, $index) = ".$h[$key].PHP_EOL;
						}
					}
					else{
						//K = 2 ÷(N + 1)
						$ii = ($i > $period)?$period: $i;
						$k = bcdiv(2, bcadd(1, $ii, EXCHANGE_ROUND_DECIMALS), EXCHANGE_ROUND_DECIMALS * 2);
						//EMA [today] = (Price [today] x K) + (EMA [yesterday] x (1 - K))
						$a = bcmul($k,  $h[$index], EXCHANGE_ROUND_DECIMALS * 2);
						$z = bcmul(bcsub(1, $k, EXCHANGE_ROUND_DECIMALS * 2), $p[$key], EXCHANGE_ROUND_DECIMALS * 2);
						$h[$key] = bcadd($a, $z, EXCHANGE_ROUND_DECIMALS * 2);
						if ($debug){
							echo "ema($period, $index) = (".$h[$index]." * $k) + (ema_p($index) * ( 1 - $k)) = $a + $z = ".$h[$key].PHP_EOL;
						}
					}
					$p = $h; $i++;
				}
			}
		}

		return $key;
	}

	// Tipical Price
	function tp(&$tickers){
		global $debug;
		if ($debug){
			echo "tp(tickers)".PHP_EOL;
		}

		$key = 'tp()';
		$t = end($tickers);

		if (!array_key_exists($key, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$t = bcadd($h['high'], $h['low'], EXCHANGE_ROUND_DECIMALS);
				$t = bcadd($t, $h['close'], EXCHANGE_ROUND_DECIMALS);
				$h[$key] = bcdiv($t, 3, EXCHANGE_ROUND_DECIMALS);
			}
		}
		return $key;
	}

	function tr(&$tickers){
		global $debug;

		if ($debug){
			echo "tr(tickers)".PHP_EOL;
		}

		$i = 1; $key = 'tr()';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				if ($i == 1){
					$previous_close = $h['low'];
				}
				else{
					$previous_close = $p['close'];
				}
				$tr1 = bcsub($h['high'], $h['low'], EXCHANGE_ROUND_DECIMALS);
				$tr2 = bcabs(bcsub($h['high'], $previous_close, EXCHANGE_ROUND_DECIMALS));
				$tr3 = bcabs(bcsub($previous_close, $h['low'], EXCHANGE_ROUND_DECIMALS));
				$p = $h; $i++;
				$h[$key] = bcmax($tr1, $tr2, $tr3);
				if ($debug){
					echo "tr() = max($tr1, $tr2, $tr3) = ".$h[$key].PHP_EOL;
				}
			}
		}

		return $key;
        }

    function min_max(&$tickers, $period = 30, $index = 'close', $decimals = EXCHANGE_ROUND_DECIMALS){
		global $debug;

		if($debug){
			echo "function min_max(tickers, $period = 30, $index = 'close', $decimals = EXCHANGE_ROUND_DECIMALS)".PHP_EOL;
		}

		//$c = round(count($tickers)/2, 0);
		$minkey = 'min('.$period.','.$index.','.$decimals.')';
		$maxkey = 'max('.$period.','.$index.','.$decimals.')';
		$absminkey = 'absmin('.$index.','.$decimals.')';
		$absmaxkey = 'absmax('.$index.','.$decimals.')';

		$stepsminkey = 'steps('.$minkey.')';
		$stepsmaxkey = 'steps('.$maxkey.')';
		$absstepsminkey = 'abssteps('.$absminkey.')';
		$absstepsmaxkey = 'abssteps('.$absmaxkey.')';
		$t = end($tickers);
		if (!array_key_exists($minkey, $t) or !array_key_exists($maxkey, $t) or !array_key_exists($stepsminkey, $t) or !array_key_exists($stepsmaxkey, $t) or !array_key_exists($absminkey, $t) or !array_key_exists($absmaxkey, $t) or !array_key_exists($absstepsminkey, $t) or !array_key_exists($absstepsmaxkey, $t)) {
			$i = 0; $buffer = array();
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
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
				}
				else{
					bcscale($decimals);
					$h[$absmaxkey] = bcmax($p[$absmaxkey], $h[$index]);
					$h[$absminkey] = bcmin($p[$absminkey], $h[$index]);

					if (count($buffer) > $period){
						array_pop($buffer);
					}

					reset($buffer);
					$h[$maxkey] = current($buffer)[$index];
					$h[$minkey] = current($buffer)[$index];

					foreach ($buffer as &$b){
						$h[$maxkey] = bcmax($b[$index], $h[$maxkey]);
						$h[$minkey] = bcmin($b[$index], $h[$minkey]);
					}

					//$h[$minkey] = number_format(bcmin($h[$index], $p[$minkey]), $decimals, '.', '');
					//$h[$maxkey] = number_format(bcmax($h[$index], $p[$maxkey]), $decimals, '.', '');
					bcscale(EXCHANGE_ROUND_DECIMALS);

					$h[$stepsmaxkey] = 0;
					$h[$stepsminkey] = 0;
					$h[$absstepsmaxkey] = 0;
					$h[$absstepsminkey] = 0;

					// Look for the absolute
                                        if (bccomp($h[$index], $h[$maxkey], $decimals) == -1){
						$h[$absstepsmaxkey] = $p[$absstepsmaxkey] + 1;
					}

					if (bccomp($h[$index], $h[$minkey], $decimals) == 1){
						$h[$absstepsminkey] = $p[$absstepsminkey] + 1;
					}

                                        // Look for the relative
					for ($ii = 1, $found = false; ($ii < count($buffer)) ; $ii++){
						//echo 'MAX '.$ii.' comparing '. $h[$maxkey] . ' vs '.$buffer[$ii][$index].PHP_EOL;
						if ((bccomp($h[$maxkey], $buffer[$ii][$index], $decimals) == 0) && !$found){
							//echo 'is '.$ii.PHP_EOL;
							$h[$stepsmaxkey] = $ii;
							$found = true;
						}
					}

					for ($jj = 1, $found = false; ($jj < count($buffer)) ; $jj++){
						//echo 'MIN '.$jj.' comparing '. $h[$minkey] . ' vs '.$buffer[$jj][$index].PHP_EOL;
						if ((bccomp($h[$minkey], $buffer[$jj][$index], $decimals) == 0) && !$found){
							//echo 'is '.$jj.PHP_EOL;
							$h[$stepsminkey] = $jj;
							$found = true;
						}
					}
				}
				$i++; $p = $h;
			}
		}
		$keys = array($minkey, $maxkey, $stepsminkey, $stepsmaxkey, $absminkey, $absmaxkey, $absstepsminkey, $absstepsmaxkey);
		return $keys;
    }

    function chop(&$tickers, $period = 20){
		global $debug;
		if ($debug){
			echo "chop(tickers, $period = 20)".PHP_EOL;
		}

		$key = 'chop('.$period.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			$buffer = array();
			$key_tr = tr($tickers);
			$bclog10_period = bclog10($period);
			list($key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max) = min_max($tickers, $period, 'high', EXCHANGE_ROUND_DECIMALS * 2);
			list($key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max) = min_max($tickers, $period, 'low', EXCHANGE_ROUND_DECIMALS * 2);

			reset($tickers);
			foreach ($tickers as &$h){
				array_push($buffer, $h[$key_tr]);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				$sum = 0;
				foreach ($buffer as &$b){
					$sum = bcadd($sum, $b, EXCHANGE_ROUND_DECIMALS * 2);
				}

				$h[$key] = bcmul(100, bcdiv(bclog10(bcdiv($sum, bcsub($h[$key_min_max_high_max], $h[$key_min_max_low_min], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2)), $bclog10_period, EXCHANGE_ROUND_DECIMALS * 2), 2);
			}
		}

		return $key;
	}

    function percentage(&$tickers, $index1 = 'close', $index2 = 'open'){
		global $debug;

		if ($debug){
			echo "percentage(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'percentage('.$index1.','.$index2.')';

		if (!array_key_exists($key, $t)){
			reset($tickers);
                        foreach ($tickers as &$h){
				$h[$key] = bcmul(
						bcdiv(
							bcsub(
								$h[$index1],
								$h[$index2], EXCHANGE_ROUND_DECIMALS * 2),
							$h[$index1], EXCHANGE_ROUND_DECIMALS * 2),
						100, 2);
			}
		}

		return $key;
	}

    function difference(&$tickers, $index1 = 'close', $index2 = 'open'){
		global $debug;

		if ($debug){
			echo "difference(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'difference('.$index1.','.$index2.')';

		if (!array_key_exists($key, $t)){
			reset($tickers);
                        foreach ($tickers as &$h){
				$h[$key] = bcsub($h[$index1], $h[$index2], EXCHANGE_ROUND_DECIMALS);
			}
		}

		return $key;
	}

	function numeric_or(&$tickers, $index1 = 'close', $index2 = 'open'){
		global $debug;

		if ($debug){
			echo "numeric_or(tickers, $index1 = 'close', $index2 = 'open')".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'or('.$index1.', '.$index2.')';

		if (!array_key_exists($key, $t)){
			$i = 0;
			reset($tickers);
			foreach ($tickers as &$h){
				$h[$key] = ((int)$h[$index1] | (int)$h[$index2]);
			}
		}

		return $key;
	}

	function consecutive(&$tickers, $index = 'close', $precision = EXCHANGE_ROUND_DECIMALS, $condition_value = null){
		global $debug;

		if ($debug){
			echo "consecutive(tickers, $index = 'close', $precision = EXCHANGE_ROUND_DECIMALS)".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'consecutive('.$index.')';

		if (!array_key_exists($key, $t)){
			$i = 0;
			reset($tickers);
			foreach ($tickers as &$h){
				if ($i == 0){
					$i = 1;
					$h[$key] = 0;
				}
				else{
					if (is_numeric($h[$index])){
						if (bccomp($p[$index], $h[$index], $precision) == 0){
							if (is_null($condition_value) || ($h[$index] == $condition_value)){
								$h[$key] = $p[$key] + 1;
							}
							else{
								$h[$key] = 0;
							}
						}
						else{
							$h[$key] = 0;
						}
					}
					else{
						if (strcasecmp($p[$index], $h[$index]) == 0){
							if (is_null($condition_value) || strcasecmp($h[$index], $condition_value) == 0){
								$h[$key] = $p[$key] + 1;
							}
							else{
								$h[$key] = 0;
							}
						}
						else{
							$h[$key] = 0;
						}
					}
				}
				$p = $h;
			}
		}

		return $key;
	}

	function delayed(&$tickers, $period = 26, $index = 'close'){
		global $debug;
		if ($debug){
			echo "delayed(tickers, $period = 26, $index = 'close')".PHP_EOL;
		}

		$key = 'delayed('.$period.','.$index.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			$i = 0; $buffer = array();
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				array_unshift($buffer, $h[$index]);     // First element is the most rescent
				if ($i == 0)
					$vz = $h[$index];

				if (count($buffer) > $period){
					$v = array_pop($buffer);
				}
				else {
					$v = $vz;
				}
				$h[$key] = $v;
				$i++;
			}
		}
		return $key;
	}

	function rename_key(&$tickers, $oldkey, $newkey){
		global $debug;

		if ($debug){
			echo "rename_key(tickers, $oldkey, $newkey)".PHP_EOL;
		}
                reset($tickers);
                foreach ($tickers as &$h){
                        $h[$newkey] = $h[$oldkey];
                        unset($h[$oldkey]);
                }

		return $tickers;
        }

	function ichimoku (&$tickers, $tenkansen = 9, $kijunsen = 26, $chikou = 26, $senkou_b = 52){
		global $debug;

		if ($debug){
			print "ichimoku (tickers, $tenkansen = 9, $kijunsen = 26, $chikou = 26, $senkou_b = 52)".PHP_EOL;
		}

		$key_tenkansen = 'tenkansen('.$tenkansen.')';
		$key_kijunsen = 'kijunsen('.$kijunsen.')';
		$key_chikou = 'chikou('.$chikou.')';
		$key_senkou_a = 'senkou_a()';
		$key_senkou_b = 'senkou_b('.$senkou_b.')';
		$t = end($tickers);

		if (!array_key_exists($key_tenkansen, $t) || !array_key_exists($key_kijunsen, $t) || !array_key_exists($key_chikou, $t) || !array_key_exists($key_senkou_a, $t) || !array_key_exists($key_senkou_b, $t)){
			list($key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max) = min_max($tickers, $tenkansen, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max) = min_max($tickers, $tenkansen, 'low', EXCHANGE_ROUND_DECIMALS);

			list($key_min_max_high_min2, $key_min_max_high_max2, $key_min_max_high_steps_min2, $key_min_max_high_steps_max2, $key_abs_min_max_high_min2, $key_abs_min_max_high_max2, $key_abs_min_max_high_steps_min2, $key_abs_min_max_high_steps_max2) = min_max($tickers, $kijunsen, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min2, $key_min_max_low_max2, $key_min_max_low_steps_min2, $key_min_max_low_steps_max2, $key_abs_min_max_low_min2, $key_abs_min_max_low_max2, $key_abs_min_max_low_steps_min2, $key_abs_min_max_low_steps_max2) = min_max($tickers, $kijunsen, 'low', EXCHANGE_ROUND_DECIMALS);

			list($key_min_max_high_min3, $key_min_max_high_max3, $key_min_max_high_steps_min3, $key_min_max_high_steps_max3, $key_abs_min_max_high_min3, $key_abs_min_max_high_max3, $key_abs_min_max_high_steps_min3, $key_abs_min_max_high_steps_max3) = min_max($tickers, $senkou_b, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min3, $key_min_max_low_max3, $key_min_max_low_steps_min3, $key_min_max_low_steps_max3, $key_abs_min_max_low_min3, $key_abs_min_max_low_max3, $key_abs_min_max_low_steps_min3, $key_abs_min_max_low_steps_max3) = min_max($tickers, $senkou_b, 'low', EXCHANGE_ROUND_DECIMALS);

			$key_delayed = delayed($tickers, 26, 'close');
                        rename_key($tickers, $key_delayed, $key_chikou);
			/*
				chikou implementation is different, instead of looking in the future, it looks in the past.
				this helps the last index to look for the value that was in the past.
			*/

			reset($tickers); $i = 0;
			foreach ($tickers as &$h){
				$k = $i - 26;
				$h[$key_tenkansen] = bcdiv(bcadd($h[$key_min_max_high_max], $h[$key_min_max_low_min]), 2, 8);
				$h[$key_kijunsen] = bcdiv(bcadd($h[$key_min_max_high_max2], $h[$key_min_max_low_min2]), 2, 8);
				if ($i >= 26){
					$h[$key_senkou_a] = bcdiv(bcadd($tickers[$k][$key_tenkansen], $tickers[$k][$key_kijunsen]), 2, 8);
					$h[$key_senkou_b] = bcdiv(bcadd($tickers[$k][$key_min_max_high_max3], $tickers[$k][$key_min_max_low_min3]), 2, 8);
				}
				else{
					$h[$key_senkou_a] = bcdiv(bcadd($h[$key_tenkansen], $h[$key_kijunsen]), 2, 8);
					$h[$key_senkou_b] = bcdiv(bcadd($h[$key_min_max_high_max3], $h[$key_min_max_low_min3]), 2, 8);
				}
				$i++;
			}
		}

		$keys = array($key_tenkansen, $key_kijunsen, $key_chikou, $key_senkou_a, $key_senkou_b);
		return $keys;
        }

    // Simple Moving Average
	function sma(&$tickers, $period = 20, $index = 'close'){
		global $debug;

		if ($debug){
			echo "sma(tickers, $period = 20, $index = 'close')".PHP_EOL;
		}

		$key = 'sma('.$period.','.$index.')';
		$t = end($tickers);

		if (!array_key_exists($key, $t)){
			$buffer = array();
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				array_push($buffer, $h[$index]);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				$sum = 0;
				foreach ($buffer as &$b){
					$sum = bcadd(bcconv($sum), $b, EXCHANGE_ROUND_DECIMALS);
				}
				$period2 = count($buffer);
				$h[$key] = bcdiv($sum, bcconv($period2), EXCHANGE_ROUND_DECIMALS);
				if ($debug){
					echo "sma($period, $index) = sum(".implode(',', $buffer).")/$period2 = $sum/$period2 = ".$h[$key].PHP_EOL;
				}
			}
		}

		return $key;
	}

	// Stochastic
    function sto(&$tickers, $period1 = 14, $period2 = 3, $period3 = 3){
		global $debug;

		if ($debug){
			echo "sto(tickers, $period1 = 14, $period2 = 3, $period3 = 3)".PHP_EOL;
		}

		$key_fastk = '%k('.$period1.')';
		$key_dk = '%dk('.$period1.','.$period2.')';
		$key_slowd = '%d('.$period1.','.$period2.','.$period3.')';
		$t = end($tickers);
		if (!array_key_exists($key_fastk, $t) or !array_key_exists($key_dk, $t) or !array_key_exists($key_slowd, $t)){
			list($key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max) = min_max($tickers, 14, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max) = min_max($tickers, 14, 'low', EXCHANGE_ROUND_DECIMALS);

			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				if ($h[$key_min_max_high_max] == $h[$key_min_max_low_min]){
					$h[$key_fastk] = 100;
				}
				else{
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
			$t = sma($tickers, $period2, $key_fastk);
			rename_key($tickers, $t, $key_dk);
			$s = sma($tickers, $period3, $key_dk);
			rename_key($tickers, $s, $key_slowd);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key_dk] = number_format($h[$key_dk], 2, '.', '');
				$h[$key_slowd] = number_format($h[$key_slowd], 2, '.', '');
			}
		}

		$keys = array($key_fastk, $key_dk, $key_slowd);
		return $keys;
	}

	function compare(&$tickers, $index = 'close', $compare = 'open', $digits = (EXCHANGE_ROUND_DECIMALS * 2)){
		global $debug;

		if ($debug){
			echo "compare(tickers, $index = 'close', $compare = 'open', $digits = (EXCHANGE_ROUND_DECIMALS * 2))".PHP_EOL;
		}

		$key = 'compare('.$index.','.$compare.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key] = bccomp(bcconv($h[$index]), bcconv($h[$compare]), $digits);
			}
		}

		return $key;
	}

	function slope(&$tickers, $index = 'close', $offset = 1){
		global $debug;

		if ($debug){
			echo "slope(tickers, $index = 'close', $offset = 1)".PHP_EOL;
		}

		$key_slope = 'slope('.$index.','.$offset.')';
		$key_sign = 'slope_sign('.$index.','.$offset.')';
		$t = end($tickers);

		if (!array_key_exists($key_slope, $t) || !array_key_exists($key_sign, $t)){
			reset($tickers); $i = 0;
			$x0 = 0; $x1 = 0 - $offset;
			foreach ($tickers as &$h){
				$y0 = $h[$index];
				if ($i >= $offset){
					$y1 = $tickers[$i - $offset][$index];
				}
				else{
					$y1 = $h[$index];
				}
				$h[$key_slope] = bcdiv(bcsub($y0, $y1, EXCHANGE_ROUND_DECIMALS), bcsub($x0, $x1, EXCHANGE_ROUND_DECIMALS),EXCHANGE_ROUND_DECIMALS);
				$h[$key_sign] = bccomp($h[$key_slope], 0);
				$i++;
			}
		}

		$keys = array($key_slope, $key_sign);
		return $keys;
	}

	function inflexion(&$tickers, $keyA = 'close',$keyB = 'ema(2,close)'){
		global $debug;
		if ($debug){
			echo "inflexion(tickers, $keyA = 'close',$keyB = 'ema(2,close)')".PHP_EOL;
		}

		$t = end($tickers);
		$key = null;

		if (array_key_exists($keyA, $t) && array_key_exists($keyB, $t)){
			$key = 'inflexion('.$keyA.','.$keyB.')';

			if (!array_key_exists($key, $t)){
				list($key_fastk, $key_dk, $key_slowd) = sto($tickers, 14, 3, 3);
				$i = 0;
				$key_compare = compare($tickers, $keyA, $keyB, EXCHANGE_ROUND_DECIMALS);
				$key_consecutive_compare = consecutive($tickers, $key_compare);
				list($key_tenkansen, $key_kijunsen, $key_chikou, $key_senkou_a, $key_senkou_b) = ichimoku($tickers);
				list($key_kijunsen_slope, $key_kijunsen_slope_sign) = slope($tickers, $key_kijunsen, 1);
				reset($tickers);
				foreach ($tickers as &$h){
					$h[$key] = 0;
					if ($h[$key_consecutive_compare] == 0){ // Inflexion point at $i - 1
						if ($i){
							$k = $i - 1;
							$fast_test = ($tickers[$k][$key_fastk] + $tickers[$k][$key_dk])/2;
							$slow_test = ($tickers[$k][$key_dk] + $tickers[$k][$key_slowd])/2;
							$k_compare = $tickers[$k][$key_compare];
							if (    (($fast_test >= 80) && ($k_compare == 1)) ||
								(($fast_test <= 20) && ($k_compare == -1)) ||
								(($slow_test >= 70) && ($k_compare == 1)) ||
								(($slow_test <= 30) && ($k_compare == -1))
							)
								$tickers[$k][$key] = 1;	// TODO: fix when normalizing is using reindexing
						}
					}
					$i++;
				}

				foreach ($tickers as &$h){
					if ($h[$key_kijunsen_slope_sign] == 0)
						$tickers[$k][$key] = 0;
				}
			}
		}

		return $key;
    }

	// Commodity Channel Index
	function cci(&$tickers, $period = 20){
		global $debug;
		if ($debug){
			echo "cci(tickers, $period = 20)".PHP_EOL;
		}

		$key = 'cci('.$period.')';
		$t = end($tickers);

		if (!array_key_exists($key, $t)){
			$buffer = array();
			$tp = tp($tickers);
			$tp_sma = sma($tickers, $period, $tp);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				array_push($buffer, $h[$tp_sma]);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				$std = stats_standard_deviation($buffer, false);
				if ($std == 0) $std = 1;
				$h[$key] = bcdiv(bcsub($h[$tp], $h[$tp_sma], EXCHANGE_ROUND_DECIMALS * 2),
						bcmul(bcconv($std), bcconv(0.015), EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
			}
		}
		return $key;
	}

	// DM+/-
	function dm(&$tickers){
		global $debug;
		if ($debug){
			echo "dm(tickers)".PHP_EOL;
		}

		$mkey = '-dm()'; $pkey = '+dm()';
		$t = end($tickersl);

                if (!array_key_exists($mkey, $t) or !array_key_exists($pkey, $t)){
			$i = 1;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				if ($i == 1){
					$h[$pkey] = $h['close'];
					$h[$mkey] = $h['close'];
				}
				else{
					$upmove = bcsub($h['high'], $p['high'], EXCHANGE_ROUND_DECIMALS);
					$downmove = bcsub($p['low'], $h['low'], EXCHANGE_ROUND_DECIMALS);
					$h[$pkey] = 0; $h[$mkey] = 0;
					if ((bccomp($upmove, $downmove, EXCHANGE_ROUND_DECIMALS * 2) > 0) and (bccomp($upmove, 0, EXCHANGE_ROUND_DECIMALS * 2) > 0)){
						$h[$pkey] = $upmove;
					}

					if ((bccomp($downmove, $upmove, EXCHANGE_ROUND_DECIMALS * 2) > 0) and (bccomp($downmove, 0, EXCHANGE_ROUND_DECIMALS * 2) > 0)){
						$h[$mkey] = $downmove;
					}
				}
				$p = $h; $i++;
			}
		}

		$keys = array($mkey, $pkey);
		return $keys;
        }

	function gain(&$tickers, $period = 14){
		global $debug;

		if ($debug){
			echo "gain(tickers, $period = 14)".PHP_EOL;
		}

		$agkey = 'average_gain('.$period.')'; $alkey = 'average_loss('.$period.')'; $dkey = 'delta('.$period.')';
		$t = end($tickers);

		if (!array_key_exists($agkey, $t) or !array_key_exists($alkey, $t)){
			$i = 1; $k = 1;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is most the recent
				if ($i < $period){
					$k = $i;
				}
				else{
					$k = $period;
				}

				if ($i == 1){                   // First element needs default values
					$h[$alkey] = $h['close'];
					$h[$agkey] = 0;
					$h['loss'] = 0;
					$h['gain'] = 0;
					$h[$dkey] = 0;
				}
				else{
					$delta = bcsub($h['close'], $p['close'], EXCHANGE_ROUND_DECIMALS * 2);
					$h[$dkey] = $delta;
					if (bccomp($delta, 0, EXCHANGE_ROUND_DECIMALS * 2) < 0){        // Loss
						$h['loss'] = bcabs(number_format($delta, EXCHANGE_ROUND_DECIMALS, '.', ''));
						$h['gain'] = 0;
					}
					else{
						$h['loss'] = 0;
						$h['gain'] = number_format($delta, EXCHANGE_ROUND_DECIMALS, '.', '');
					}
					// Could be smma
					$k1 = $k - 1;
					$h[$alkey] = bcdiv(bcadd(bcmul($p[$alkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h['loss'], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
					$h[$agkey] = bcdiv(bcadd(bcmul($p[$agkey], $k1, EXCHANGE_ROUND_DECIMALS * 2), $h['gain'], EXCHANGE_ROUND_DECIMALS * 2), $k, EXCHANGE_ROUND_DECIMALS * 2);
				}
				$p = $h; $i++;
			}
		}

		$keys = array('gain', 'loss', $alkey, $agkey, $dkey);
		return $keys;
	}

	// RSI
	function rsi(&$tickers, $period = 14){
		global $debug;

		if ($debug){
			echo "rsi(tickers, $period = 14)".PHP_EOL;
		}

		$key = 'rsi('.$period.')';
		$t = end($tickers);

		if (!array_key_exists($key, $t)){
			gain($tickers, $period);
			$gain_key = ema($tickers, $period, 'gain');
			$loss_key = ema($tickers, $period, 'loss');

			reset($tickers);
			foreach ($tickers as &$h){      // Last element is most rescent
				if (bccomp($h[$loss_key], 0, EXCHANGE_ROUND_DECIMALS * 2) > 0){
					$rs = bcdiv($h[$gain_key], $h[$loss_key], EXCHANGE_ROUND_DECIMALS * 2);
					//RSI = (100 – (100 / (1 + RS)))
					$h[$key] = bcsub(100, bcdiv(bcconv(100), bcadd(bcconv(1), bcconv($rs), EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2), 2);
				}
				else{
					$h[$key] = 100;
				}
			}
		}

		return $key;
    }

    function roc(&$tickers, $delay = 1, $index = 'close'){
        global $debug;

        if ($debug){
            echo "rsi(tickers, $period = 14)".PHP_EOL;
        }

        $key = 'roc('.$delay.','.$index.')';
        $t = end($tickers);

        if (!array_key_exists($key, $t)){
            $delayed_index = delayed($tickers, $delay, $index);

            reset($tickers);
            $i = 0;
            foreach ($tickers as &$h){      // Last element is most rescent
                if ($i == 0){
                    $h[key] = "100.00";
                    $i = 1;
                }
                else{
                    $h[key] = bcmul(bcdiv(bcsub($h[$index], $h[$delayed_index], EXCHANGE_ROUND_DECIMALS * 2), $h[$delayed_index], EXCHANGE_ROUND_DECIMALS * 2), 100, 2);
                }
            }
        }

        return $key;
    }

	function macd(&$tickers, $short_period = 12, $long_period = 26, $signal_period = 9){
		global $debug;

		if ($debug){
			echo "macd(tickers, $short_period = 12, $long_period = 26, $signal_period = 9)".PHP_EOL;
		}

		$t = end($tickers);
		$macdkey = 'macd('.$short_period.','.$long_period.','.$signal_period.')';
		$sigkey = 'macd_signal('.$macdkey.')';
		if (!array_key_exists($macdkey, $t)){
			$skey = ema($tickers, $short_period, 'close');
			$lkey = ema($tickers, $long_period, 'close');

			reset($tickers);
			foreach ($tickers as &$h){
				$h[$macdkey] = bcsub($h[$skey], $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2);
			}
		}

		$emasigkey = ema($tickers, $signal_period, $macdkey);
		rename_key($tickers, $emasigkey, $sigkey);

		$dkey = 'delta_macd('.$macdkey.','.$sigkey.')';
		if (!array_key_exists($dkey, $t)){
			reset($tickers);
			foreach ($tickers as &$h){
				$h[$dkey] = bcsub($h[$macdkey], $h[$sigkey], EXCHANGE_ROUND_DECIMALS * 2);
			}
		}

		$keys = array($macdkey, $sigkey, $dkey);
		return $keys;
	}

    // Percentage Price Oscillator
	function ppo(&$tickers, $short_period = 12, $long_period = 26, $signal_period = 9){
        global $debug;

        if ($debug){
            echo "ppo(tickers, $short_period = 12, $long_period = 26, $signal_period = 9)".PHP_EOL;
        }

        $t = end($tickers);
        $ppokey = 'ppo('.$short_period.','.$long_period.','.$signal_period.')';
        $sigkey = 'ppo_signal('.$ppokey.')';
        if (!array_key_exists($ppokey, $t)){
            $skey = ema($tickers, $short_period, 'close');
            $lkey = ema($tickers, $long_period, 'close');

            reset($tickers);
            foreach ($tickers as &$h){
                $h[$ppokey] = bcmul(bcdiv(bcsub($h[$skey], $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2), $h[$lkey], EXCHANGE_ROUND_DECIMALS * 2), 100, 2);
            }
        }

        $emasigkey = ema($tickers, $signal_period, $ppokey);
        rename_key($tickers, $emasigkey, $sigkey);

        $dkey = 'delta_ppo('.$ppokey.','.$sigkey.')';
        if (!array_key_exists($dkey, $t)){
            reset($tickers);
            foreach ($tickers as &$h){
                $h[$dkey] = bcsub($h[$ppokey], $h[$sigkey], EXCHANGE_ROUND_DECIMALS * 2);
            }
        }

        $keys = array($ppokey, $sigkey, $dkey);
        return $keys;
    }

	function atr(&$tickers, $period = 14, string $average_function = 'sma'){
		global $debug;
		if ($debug){
			echo "atr(tickers, $period = 14, $average_function = 'sma')".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'atr('.$period.')';

		if (!array_key_exists($key, $t)){
			$tr_key = tr($tickers);
			if (function_exists('\\okayinc\\trademinator\\indicators\\'.$average_function)){

				if ($debug){
					echo $average_function.' found'.PHP_EOL;
				}

				switch ($average_function){
					case 'sma':
						$okey = sma($tickers, $period, $tr_key);
						break;
					case 'ema':
						$okey = ema($tickers, $period, $tr_key);
				}

			}
			else{
				if ($debug){
					echo $average_function.' not found, using default SMA'.PHP_EOL;
				}
				$okey = sma($tickers, $period, $tr_key);	// Investopedia sugest a SMA, others a EMA
			}
			// Rename ema/sma(period,tr) new key into atr one
			rename_key($tickers, $okey, $key);
		}

		return $key;
	}

	function atrp(&$tickers, $period = 14,  string $average_function = 'sma'){
		global $debug;
		if ($debug){
			echo "atrp(tickers, $period = 14)".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'atrp('.$period.')';
		if (!array_key_exists($key, $t)){
			$atr_key = atr($tickers, $period, $average_function);
			reset($tickers);
			foreach ($tickers as &$h){
				if (floatval($h['close']) == 0.0){
					$c = 1/EXCHANGE_ROUND_DECIMALS;
				}
				else {
					$c = $h['close'];
				}
				$h[$key] = bcmul(bcdiv($h[$atr_key], $c, EXCHANGE_ROUND_DECIMALS * 2), 100, EXCHANGE_ROUND_DECIMALS * 2);
				if ($debug){
					echo "atrp($period) = 100 * atr($period) / close = 100 * ".$h[$atr_key].'/'.$h['close'].' = '.$h[$key].PHP_EOL;
				}
			}
		}

		return $key;
	}

	// Smoothed Moving Average
	function smma(&$tickers, $period = 20, $index = 'close'){
		global $debug;
		if ($debug){
			echo "smma(tickers, $period = 20, $index = 'close')".PHP_EOL;
		}

		$key = 'smma('.$period.','.$index.')';
		$t = end($tickers);
		if (!array_key_exists($key, $t)){
			$sma_key = sma($tickers, $period, $index);
			$i = 0;
			$k = $period - 1;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				if ($i == 0){
					$h[$key] = $h[$sma_key];
					$i = 1;
				}
				else{
					$h[$key] = bcdiv(bcadd(bcmul($p[$key], $k, EXCHANGE_ROUND_DECIMALS * 2), $h[$index], EXCHANGE_ROUND_DECIMALS * 2), $period, EXCHANGE_ROUND_DECIMALS * 2);
				}
				$p = $h;
			}
		}

		return $key;
	}

	function adx(&$tickers, $period = 14){
		global $debug;
		if ($debug){
			echo "adx(&$tickers, $period = 14)".PHP_EOL;
		}

		$adx_key = 'adx('.$period.')';
		$dx_key = 'dx()';
		$pkey = '+di('.$period.')';
		$mkey = '-di('.$period.')';
		$t = end($tickers);
		if (!array_key_exists($mkey, $t) or !array_key_exists($pkey, $t) or !array_key_exists($adx_key, $t) or !array_key_exists($dx_key, $t)){
			$tr_key = tr($tickers);
			list($minus_dm_key, $plus_dm_key) = dm($tickers);
			$sma_dmp_key = sma($tickers, $period, $plus_dm_key);
			$sma_dmm_key = sma($tickers, $period, $minus_dm_key);
			$sma_tr_key = sma($tickers, $period, $tr_key);
			$tpkey = "t$pkey"; $tmkey = "t$mkey";

			reset($tickers);
			foreach ($tickers as &$h){
				$tp = bcabs(bcdiv($h[$sma_dmp_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
				$tm = bcabs(bcdiv($h[$sma_dmm_key], $h[$sma_tr_key], EXCHANGE_ROUND_DECIMALS * 2));
				$h[$pkey] = bcmul(100, $tp, 2);
				$h[$mkey] = bcmul(100, $tm, 2);

				$sub = bcsub($h[$pkey], $h[$mkey], EXCHANGE_ROUND_DECIMALS * 2);
				$add = bcadd($h[$pkey], $h[$mkey], EXCHANGE_ROUND_DECIMALS * 2);
				if (bccomp($add, 0, EXCHANGE_ROUND_DECIMALS * 2)){
					$h[$dx_key] = bcmul(100, bcabs(bcdiv($sub, $add, EXCHANGE_ROUND_DECIMALS * 2)), 2);
				}
				else{
					$h[$dx_key] = 0;
				}
			}
			$ttkey = smma($tickers, $period, $dx_key); // $ttkey = 'ema('.$period.','.$tkey.')';
                        rename_key($tickers, $ttkey, $adx_key);

                        reset($tickers);
                        foreach ($tickers as &$h){
                                $h[$adx_key] = number_format($h[$adx_key], 2, '.', '');
                        }
                }

		$keys = array($adx_key, $dx_key, $mkey, $pkey);
		return $keys;
	}

	function midkey(&$tickers, $key1 = 'high', $key2 = 'low'){
		global $debug;
		if ($debug){
			echo "midkey(tickers, $key1 = 'high', $key2 = 'low')".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'average('.$key1.','.$key2.')';
		if (!array_key_exists($key, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$sum = bcadd($h[$key1], $h[$key2], EXCHANGE_ROUND_DECIMALS);
				$h[$key] = bcdiv($sum, 2, EXCHANGE_ROUND_DECIMALS);
				if ($debug){
					echo "midkey($key1, $key2) = (".$h[$key1]." + ".$h[$key2].")/2 = $sum/2 = ".$h[$key].PHP_EOL;
				}
			}
		}

		return $key;
	}

	// Awesome Oscillator
	function ao(&$tickers, $short = 5, $long = 34){
		global $debug;
		if ($debug){
			echo "ao(tickers, $short = 5, $long = 34)".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'ao('.$short.','.$long.')';
		$key_color = 'ao_color('.$short.','.$long.')';
		$midkey = midkey($tickers);
		if (!array_key_exists($key, $t) or !array_key_exists($midkey, $t) or !array_key_exists($key_color, $t)){
			$sma_short_key = sma($tickers, $short, $midkey);
			$sma_long_key = sma($tickers, $long, $midkey);

			$i = 1;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key] = bcsub($h[$sma_short_key], $h[$sma_long_key], EXCHANGE_ROUND_DECIMALS * 2);
				if ($i == 1){
					$h[$key_color] = 'green';
				}
				else{
					if (bccomp($h[$key], $p[$key], EXCHANGE_ROUND_DECIMALS * 2) > -1){
						$h[$key_color] = 'green';
					}
					else{
						$h[$key_color] = 'red';
					}
				}
				$p = $h; $i++;
			}
		}

		$keys = array($key, $key_color, $midkey);
		return $keys;
	}

	// Accelerator
	function ac(&$tickers, $period = 5){
		global $debug;
		if ($debug){
			echo "ac(&$tickers, $period = 5)";
		}

		$key = 'ac('.$period.')';
		$t = end($tickers);

		if (!array_key_exists($key, $t)){
			list($key_ao, $key_ao_color, $midkey) = ao($tickers, $period, 34);
			$smakey = sma($tickers, $period, $key_ao);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key] = bcsub($h[$key_ao], $h[$smakey], EXCHANGE_ROUND_DECIMALS * 2);
			}
		}

		return $key;
	}

	// Bollinger Band
	function bb(&$tickers, $period = 20, $stddev = 2){
		global $debug;
		if ($debug){
			echo "bb(tickers, $period = 20, $stddev = 2)".PHP_EOL;
		}

		$t = end($tickers);
		$keyhbb = 'bb_high('.$period.','.$stddev.')';
		$keylbb = 'bb_low('.$period.','.$stddev.')';
		$bb_qz = 'bb_bw('.$period.','.$stddev.')';
		$keystd = 'stddev('.$period.')';
		$sma_key = sma($tickers,$period, 'close');
		if (!array_key_exists($keyhbb, $t) or !array_key_exists($keylbb, $t) or !array_key_exists($bb_qz, $t) or !array_key_exists($keystd, $t)){
			$buffer = array(); $i = 0;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				array_push($buffer, $h['close']);
				if (count($buffer) > $period){
					array_shift($buffer);
				}
				if (count($buffer) > 1){
					$std = stats_standard_deviation($buffer, false);
					$h[$keystd] = $std;
					$h[$keyhbb] = bcadd($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
					$h[$keylbb] = bcsub($h[$sma_key], bcmul($stddev, $std, EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
					$h[$bb_qz] = bcdiv(bcsub($h[$keyhbb], $h[$keylbb], EXCHANGE_ROUND_DECIMALS * 2), $h[$sma_key], EXCHANGE_ROUND_DECIMALS * 2);
				}
			}
		}

		$keys = array($keylbb, $keyhbb, $bb_qz, $keystd, $sma_key);
                return $keys;
    }

	// Keltner
	function keltner(&$tickers, $period = 20, $bandwidth = 1.5){
		global $debug;
		if ($debug){
			echo "keltner(tickers, $period = 20, $bandwidth = 1.5)".PHP_EOL;
		}

		$t = end($tickers);
		$keyhk = 'keltner_high('.$period.','.$bandwidth.')';
		$keylk = 'keltner_low('.$period.','.$bandwidth.')';
		$key_tp = tp($tickers);
		$sma_key = sma($tickers, $period, $key_tp);
		if (!array_key_exists($keyhk, $t) or !array_key_exists($keylk, $t)){
			$key_atr = atr($tickers, 14);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$keyhk] = bcadd($h[$sma_key], bcmul($bandwidth, $h[$key_atr], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
				$h[$keylk] = bcsub($h[$sma_key], bcmul($bandwidth, $h[$key_atr], EXCHANGE_ROUND_DECIMALS * 2), EXCHANGE_ROUND_DECIMALS * 2);
			}
		}

		$keys = array($keylk, $keyhk, $sma_key);
		return $keys;
	}

	function squeeze(&$tickers){
		global $debug;
		if ($debug){
			echo "squeeze(tickers)".PHP_EOL;
		}

		$t = end($tickers);
		$key = 'squeeze()';
		$key_choppy = 'choppy()';
		if (!array_key_exists($key, $t) or !array_key_exists($key_choppy, $t)){
			list($key_lbb_20_2, $key_hbb_20_2, $key_bb_qz_20_2, $key_std_20_2, $key_sma_20_2) = bb($tickers, 20, 2);
			list($key_lk, $key_hk, $key_sma_close) = keltner($tickers, 20, 1.5);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key] = bcsub($h[$key_hbb_20_2], $h[$key_hk], EXCHANGE_ROUND_DECIMALS * 2);
				if (bccomp(bcconv($h[$key]), bcconv('0.0'), EXCHANGE_ROUND_DECIMALS) > 0){
					$h[$key_choppy] = 0;
				}
				else{
					$h[$key_choppy] = 1;
				}
			}
		}

		$keys = array($key, $key_choppy);
		return $keys;
	}

	function super_trend(&$tickers, $period = 10, $factor = 3){
		global $debug;
		if ($debug){
			echo "super_trend(tickers, $period = 10, $factor = 3)".PHP_EOL;
		}

		$t = end($tickers);
		$key_super = 'super_trend('.$period.','.$factor.')';
		$key_upper = 'super_trend_upper('.$period.','.$factor.')';
		$key_lower  = 'super_trend_lower('.$period.','.$factor.')';
		if (!array_key_exists($key_super, $t) or !array_key_exists($key_upper, $t) or !array_key_exists($key_lower, $t)){
			$key_hl2 = midkey($tickers, 'high', 'low');
			$key_atr = atr($tickers, 14);
			$i = 1;
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key_upper] = $h[$key_hl2] + $factor * $h[$key_atr];
				$h[$key_lower] = $h[$key_hl2] - $factor * $h[$key_atr];
				$h[$key_super] = $h[$key_upper];
				$note = -1;
				if ($i == 1){
					// Keep the current values
				}
				else{
					if (($h[$key_lower] > $p[$key_lower]) || ($p['close'] < $p[$key_lower])){
						// Keep the value
					}
					else {
						$h[$key_lower] = $p[$key_lower] ;
					}

					if (($h[$key_upper] < $p[$key_upper]) || ($p['close'] > $p[$key_upper])){
						// Keep the value
					}
					else {
						$h[$key_upper] = $p[$key_upper];
					}

					if ($p[$key_super] == $p[$key_upper]){
						$note = ($h['close'] > $h[$key_upper])? 1:-1;
					}
					else{
						$note = ($h['close'] < $h[$key_lower])? -1:1;
					}
				}

				$p = $h; $i++;
				$h[$key_super] = ($note == 1)? $h[$key_lower]:$h[$key_upper];
			}
		}
		$keys = array($key_super, $key_upper, $key_lower);
		return $keys;
	}

	function price_speed(&$tickers, $period = 1, $bar_time_in_seconds = null){
		global $debug;
		if ($debug){
			echo "price_speed(tickers, $period = 1, $bar_time_in_seconds = null)".PHP_EOL;
		}

		if ((count($tickers) < 2) && (is_null($bar_time_in_seconds))){
			// Cant know the time
			return null;
		}
		else{
			if (count($tickers) > 1){
				// ignore bar_time_in_seconds and calculate it
				$c = end($tickers);
				$d = prev($tickers);
				if (is_null($c) || is_null($d)){
					return null;
				}
				$bar_time_in_seconds = abs($c[0] - $d[0]) / 1000;	// delta is in seconds

			}
		}

		$t = end($tickers);
		$key  = 'speed('.$period.','.$bar_time_in_seconds.')';
		if (!array_key_exists($key, $t)){
			$last_close = delayed($tickers, $period, 'close');
			reset($tickers);
			$c = 0;
			foreach ($tickers as &$h){      // Last element is the most rescent
				$c++;
				if ($c > $period){
					$c = $period;
				}
				$diff = bcsub(bcconv($h['close']), bcconv($h[$last_close]), EXCHANGE_ROUND_DECIMALS);
				$time = $c * $bar_time_in_seconds;
				$h[$key] = bcdiv($diff, $time, EXCHANGE_ROUND_DECIMALS);
				if ($debug){
					echo "speed($period,$bar_time_in_seconds) = (".$h['close'].' - '.$h[$last_close].") / ($c * $bar_time_in_seconds) = $diff / $time = ".$h[$key].PHP_EOL;
				}
			}
		}

		return $key;
	}

	function candle_anatomy(&$tickers){
		global $debug;

		if ($debug){
			echo "candle_anatomy(tickers)".PHP_EOL;
		}

		$key_is_black = 'is_black()';
		$key_is_long_black = 'is_long_black()';
		$key_is_short_black = 'is_short_black()';
		$key_is_black_marubozu = 'is_black_marubozu()';
		$key_is_white = 'is_white()';
		$key_is_long_white = 'is_long_white()';
		$key_is_short_white = 'is_short_white()';
		$key_is_white_marubozu = 'is_white_marubozu()';
		$key_is_doji = 'is_doji()';
		$key_is_super_doji = 'is_super_doji()';
		$t = end($tickers);
		if (
			!array_key_exists($key_is_black, $t) or !array_key_exists($key_is_long_black, $t) or !array_key_exists($key_is_short_black, $t) or !array_key_exists($key_is_black_marubozu, $t) or
			!array_key_exists($key_is_white, $t) or !array_key_exists($key_is_long_white, $t) or !array_key_exists($key_is_short_white, $t) or !array_key_exists($key_is_white_marubozu, $t) or
			!array_key_exists($key_is_doji, $t) or !array_key_exists($key_is_super_doji, $t)
		){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				// TODO: check if bcmath is needed
				$h[$key_is_black] = (int)($h['open'] > $h['close']);
				$h[$key_is_long_black] = (int)(($h['open'] > $h['close']) && ((($h['open'] - $h['close'])/(0.001 + $h['high'] - $h['low'])) > 0.6));
				$h[$key_is_short_black] = (int)(($h['open'] > $h['close']) && (($h['high'] - $h['low']) > (3 * ($h['open'] - $h['close']))));
				$h[$key_is_black_marubozu] = (int)(($h['open'] > $h['close']) & ($h['high'] == $h['open']) & ($h['close'] == $h['low']));
				$h[$key_is_white] = (int)($h['close'] > $h['open']);
				$h[$key_is_long_white] = (int)(($h['close'] > $h['open']) && ((($h['close'] - $h['open'])/(0.001 + $h['high'] - $h['low'])) > 0.6));
				$h[$key_is_short_white] = (int)(($h['close'] > $h['open']) && (($h['high'] - $h['low']) > (3 * ($h['close'] - $h['open']))));
				$h[$key_is_white_marubozu] = (int)(($h['close'] > $h['open']) & ($h['high'] == $h['close']) & ($h['open'] == $h['low']));
				$h[$key_is_doji] = (int)($h['open'] == $h['close']);
				$h[$key_is_super_doji] = (int)(($h['open'] == $h['close']) && (($h['high'] == $h['low'])));
				if ($debug){
					echo "is_black() = ".$h[$key_is_black].PHP_EOL;
					echo "is_long_black() = ".$h[$key_is_long_black].PHP_EOL;
					echo "is_short_black() = ".$h[$key_is_short_black].PHP_EOL;
					echo "is_black_marubozu() = ".$h[$key_is_black_marubozu].PHP_EOL;
					echo "is_white() = ".$h[$key_is_black].PHP_EOL;
					echo "is_long_white() = ".$h[$key_is_long_black].PHP_EOL;
					echo "is_short_white() = ".$h[$key_is_short_black].PHP_EOL;
					echo "is_white_marubozu() = ".$h[$key_is_black_marubozu].PHP_EOL;
					echo "is_doji() = ".$h[$key_is_doji].PHP_EOL;
					echo "is_super_doji() = ".$h[$key_is_super_doji].PHP_EOL;
				}
			}
		}

		return array($key_is_black, $key_is_long_black, $key_is_short_black, $key_is_black_marubozu, $key_is_white, $key_is_long_white, $key_is_short_white, $key_is_white_marubozu, $key_is_doji, $key_is_super_doji);
    }

// TODO: finish
	function is_dragonfly(&$tickers){
		global $debug;

		if ($debug){
			echo "is_dragonfly(tickers)".PHP_EOL;
		}

		$key_is_bullish_dragonfly = 'is_bullish_dragonfly()';
		$key_is_bearish_dragonfly = 'is_bearish_dragonfly()';
		$t = end($tickers);
		if (!array_key_exists($key_is_bullish_dragonfly, $t) or !array_key_exists($key_is_bearish_dragonfly, $t)){
			$key_sma_10_high = sma($tickers, 10, 'high');
			$key_sma_10_low = sma($tickers, 10, 'low');
			list($key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max) = \okayinc\trademinator\indicators\min_max($ohlcv, 10, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max) = \okayinc\trademinator\indicators\min_max($ohlcv, 10, 'low', EXCHANGE_ROUND_DECIMALS);
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent

//				$h[$key_is_bullish_dragonfly] =
//					(	(int)((abs() <= 0.02*()) && (() <= 0.3*()) && (() >= ()) && ( > ) && ( == )) ) ||
//					(	((() > 3*abs()) && (() > 0.8*())) && (() > 0.8*()));
				if ($debug){
				}
			}
		}
	}

	function is_grave_stone(&$tickers){
		global $debug;

		if ($debug){
			echo "is_grave_stone(tickers)".PHP_EOL;
		}

		$key_is_bullish_grave_stone = 'is_bullish_grave_stone()';
		$key_is_bearish_grave_stone = 'is_bearish_grave_stone()';
		$t = end($tickers);
		if (!array_key_exists($key_is_bullish_grave_stone, $t) or !array_key_exists($key_is_bearish_grave_stone, $t)){
			$key_sma_10_high = sma($tickers, 10, 'high');
			$key_sma_10_low = sma($tickers, 10, 'low');
			list($key_min_max_high_min, $key_min_max_high_max, $key_min_max_high_steps_min, $key_min_max_high_steps_max, $key_abs_min_max_high_min, $key_abs_min_max_high_max, $key_abs_min_max_high_steps_min, $key_abs_min_max_high_steps_max) = \okayinc\trademinator\indicators\min_max($ohlcv, 10, 'high', EXCHANGE_ROUND_DECIMALS);
			list($key_min_max_low_min, $key_min_max_low_max, $key_min_max_low_steps_min, $key_min_max_low_steps_max, $key_abs_min_max_low_min, $key_abs_min_max_low_max, $key_abs_min_max_low_steps_min, $key_abs_min_max_low_steps_max) = \okayinc\trademinator\indicators\min_max($ohlcv, 10, 'low', EXCHANGE_ROUND_DECIMALS);
			reset($tickers);

		}
	}


	function is_inverted_hammer_or_shooting_star(&$tickers){
		global $debug;

		if ($debug){
			echo "is_inverted_hammer(tickers)".PHP_EOL;
		}

		$key_is_inverted_hammer = 'is_inverted_hammer()';
		$key_is_shooting_star = 'is_shooting_star()';
		$t = end($tickers);
		if (!array_key_exists($key_is_inverted_hammer, $t) or !array_key_exists($key_is_shooting_star, $t)){
			reset($tickers);
			foreach ($tickers as &$h){      // Last element is the most rescent
				$h[$key_is_inverted_hammer] = (int)((($h['high'] - $h['low']) > 3*($h['open'] - $h['close'])) && ((($h['high'] - $h['close'])/(0.001 + $h['high'] - $h['low'])) > 0.6) && ((($h['high'] - $h['open'])/(0.001 + $h['high'] - $h['low'])) > 0.6));
				$h[$key_is_shooting_star] = (int)((($h['high'] - $h['low']) > 4*($h['open'] - $h['close'])) && ((($h['high'] - $h['close'])/(0.001 + $h['high'] - $h['low'])) >= 0.75) && ((($h['high'] - $h['open'])/(0.001 + $h['high'] - $h['low'])) >= 0.75));
				if ($debug){
					echo "is_inverted_hammer() = ".$h[$key_is_inverted_hammer].PHP_EOL;
					echo "is_shooting_star() = ".$h[$key_is_shooting_star].PHP_EOL;
				}
			}
		}

		return array($key_is_inverted_hammer, $key_is_shooting_star);
	}
}
