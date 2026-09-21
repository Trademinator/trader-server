<?php
namespace App\Traits;

trait Indexing{

    function normalize(array &$tickers, $reindex = false){
        foreach ($tickers as &$ticker){
            $ticker['human_date'] = date('Y-m-d H:i:s', $ticker[0]/1000);
            $ticker['microtimestamp'] = $ticker[0];
            if (array_key_exists(1, $ticker)) { $ticker['open'] = sprintf('%f', $ticker[1]); unset($ticker[1]); }
            if (array_key_exists(2, $ticker)) { $ticker['high'] = sprintf('%f', $ticker[2]); unset($ticker[2]); }
            if (array_key_exists(3, $ticker)) { $ticker['low'] = sprintf('%f', $ticker[3]); unset($ticker[3]); }
            if (array_key_exists(4,$ticker)) { $ticker['close'] = sprintf('%f', $ticker[4]); unset($ticker[4]); }
            if (array_key_exists(5,$ticker)) { $ticker['volume'] = sprintf('%f', $ticker[5]); unset($ticker[5]); }
        }

        if ($reindex){
            $nt = array();
            // Use $ticker[0] as index
            foreach ($tickers as &$ticker){
                    $nt[$ticker[0]] = &$ticker;
                    unset($nt[$ticker[0]][0]);
            }
            $tickers = $nt;
        }

        return $tickers;
    }

}
