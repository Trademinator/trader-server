<?php
namespace App\Traits;

trait Indexing{

    function normalize(array &$tickers, $reindex = false){
        foreach ($tickers as &$ticker){
            $ticker['human_date'] = date('Y-m-d H:i:s', $ticker[0]/1000);
            $ticker['microtimestamp'] = $ticker[0];
<<<<<<< HEAD
            if (array_key_exists(1, $ticker)) { $ticker['open'] = sprintf('%.8f', $ticker[1]); unset($ticker[1]); }
            if (array_key_exists(2, $ticker)) { $ticker['high'] = sprintf('%.8f', $ticker[2]); unset($ticker[2]); }
            if (array_key_exists(3, $ticker)) { $ticker['low'] = sprintf('%.8f', $ticker[3]); unset($ticker[3]); }
            if (array_key_exists(4,$ticker)) { $ticker['close'] = sprintf('%.8f', $ticker[4]); unset($ticker[4]); }
            if (array_key_exists(5,$ticker)) { $ticker['volume'] = sprintf('%.8f', $ticker[5]); unset($ticker[5]); }
=======
            if (array_key_exists(1, $ticker)) { $ticker['open'] = (string) $ticker[1]; unset($ticker[1]); }
            if (array_key_exists(2, $ticker)) { $ticker['high'] = (string) $ticker[2]; unset($ticker[2]); }
            if (array_key_exists(3, $ticker)) { $ticker['low'] = (string) $ticker[3]; unset($ticker[3]); }
            if (array_key_exists(4,$ticker)) { $ticker['close'] = (string) $ticker[4]; unset($ticker[4]); }
            if (array_key_exists(5,$ticker)) { $ticker['volume'] = (string) $ticker[5]; unset($ticker[5]); }
            unset($ticker[0]);
>>>>>>> cb9ff2a (first commit)
        }

        if ($reindex){
            $nt = array();
            // Use $ticker[0] as index
            foreach ($tickers as &$ticker){
                    $nt[$ticker['microtimestamp']] = $ticker;
            }
            $tickers = $nt;
        }

        return $tickers;
    }

}
