<?php

namespace App\Traits;

use ccxt\Exchange;

trait IsSupportedByCCXT
{
    public function isSupportedByCCXT(string $exchange, string $symbol, string $period): bool
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
