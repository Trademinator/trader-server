<?php

namespace App\Traits;

use ccxt\Exchange;

/**
// Trend
                $key_ema_3_close = \okayinc\trademinator\indicators\ema($ohlcv, 3, 'close');
                $key_ema_12_close = \okayinc\trademinator\indicators\ema($ohlcv, 12, 'close');
                $key_inflexion_ema_3_close_ema_12_close = \okayinc\trademinator\indicators\inflexion($ohlcv, $key_ema_3_close, $key_ema_12_close);
                $key_delayed_4_close = \okayinc\trademinator\indicators\delayed($ohlcv, 4, 'close');
                $key_delayed_12_close = \okayinc\trademinator\indicators\delayed($ohlcv, 12, 'close');
                $key_percentage_close_difference_close_delayed_4_close = \okayinc\trademinator\indicators\percentage($ohlcv, 'close', $key_delayed_4_close);
                $key_percentage_close_difference_close_delayed_12_close = \okayinc\trademinator\indicators\percentage($ohlcv, 'close', $key_delayed_12_close);

// Momentum
                $key_rsi_3 = \okayinc\trademinator\indicators\rsi($ohlcv, 3);
                $key_rsi_14 = \okayinc\trademinator\indicators\rsi($ohlcv, 14);
                list($key_sto_rsi_14_14_3_3_fastk, $key_sto_rsi_14_14_3_3_dk, $key_sto_rsi_14_14_3_3_slowd) = \okayinc\trademinator\indicators\sto($ohlcv, $key_rsi_14, 14, 3, 3);
                $key_cci_20 = \okayinc\trademinator\indicators\cci($ohlcv, 20);

// Volativity
                $key_atrp_3 = \okayinc\trademinator\indicators\atrp($ohlcv, 3);
                $key_atrp_14 = \okayinc\trademinator\indicators\atrp($ohlcv, 14);
------------------------------

// Trend
                $keys[] = $key_inflexion_ema_3_close_ema_12_close;
                $keys[] = $key_percentage_close_difference_close_delayed_4_close;
                $keys[] = $key_percentage_close_difference_close_delayed_12_close;

// Momentum
                $keys[] = $key_rsi_3;
                $keys[] = $key_rsi_14;
                $keys[] = $key_sto_rsi_14_14_3_3_fastk;
                $keys[] = $key_cci_20;

// Volativity
                $keys[] = $key_atrp_3;
                $keys[] = $key_atrp_14;

// Volume

// Bar description
                $keys[] = $key_percentage_open_close;
                $keys[] = $key_percentage_delayed_1_open_close;
                $keys[] = $key_percentage_delayed_3_open_close;
                $keys[] = $key_percentage_delayed_14_open_close;

 **/
trait AnalizeTicker
{
    public function analizeTicker(string $exchange, string $symbol, string $period): bool
    {
        $answer = false;
        if (in_array($exchange, Exchange::$exchanges)) {
            $ccxtExchangeName = '\\ccxt\\'.$exchange;
            $ccxtExchange = new $ccxtExchangeName([]);
            if (in_array($symbol, array_keys($ccxtExchange->load_markets()))) {
                if (in_array($period, array_keys($ccxtExchange->describe()['timeframes']))) {
                    $answer = true;
                }
            }
        }

        return $answer;
    }
}
