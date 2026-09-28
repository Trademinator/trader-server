<?php

namespace App\Traits;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

trait TickerManipulation
{
	public function normalize_ticker(&$tickers, $reindex = false){
		foreach ($tickers as &$ticker){
			$ticker['human_date'] = date('YmdHis', $ticker[0]/1000);
			if (isset($ticker[1])) { $ticker['open'] = sprintf('%f', $ticker[1]); unset($ticker[1]); }
			if (isset($ticker[2])) { $ticker['high'] = sprintf('%f', $ticker[2]); unset($ticker[2]); }
			if (isset($ticker[3])) { $ticker['low'] = sprintf('%f', $ticker[3]); unset($ticker[3]); }
			if (isset($ticker[4])) { $ticker['close'] = sprintf('%f', $ticker[4]); unset($ticker[4]); }
			if (isset($ticker[5])) { $ticker['volume'] = sprintf('%f', $ticker[5]); unset($ticker[5]); }
                }

		if ($reindex){
			$nt = array();
			// Use $ticker[0] as index
			foreach ($tickers as &$ticker){
				$nt[$ticker[0]] = &$ticker;
			}

			$tickers = $nt;
		}

		return $tickers;
	}

    public function delete_key(&$tickers, ...$keys)	// TODO: fix performance
    {foreach ($tickers as &$h) {
        foreach ($keys as &$key) {
            unset($h[$key]);
        }
    }
    }

    public function clone_key(&$tickers, $oldkey, $newkey)
    {
        reset($tickers);
        foreach ($tickers as &$h) {
            $h[$newkey] = $h[$oldkey];
        }

        return $tickers;
    }


}
