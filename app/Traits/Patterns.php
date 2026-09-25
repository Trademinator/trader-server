<?php

namespace App\Traits;

if (! defined('EXCHANGE_ROUND_DECIMALS')) {
    define('EXCHANGE_ROUND_DECIMALS', 8);
}

/**
 * Experimental candlestick patterns are quarantined until each routine has
 * deterministic fixtures and tests. Completed routines remain available.
 */
trait Patterns
{
    use Technical;

    public function candle_anatomy(&$tickers)
    {
        global $debug;

        if ($debug) {
            echo 'candle_anatomy(tickers)'.PHP_EOL;
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
            ! array_key_exists($key_is_black, $t) or ! array_key_exists($key_is_long_black, $t) or ! array_key_exists($key_is_short_black, $t) or ! array_key_exists($key_is_black_marubozu, $t) or
            ! array_key_exists($key_is_white, $t) or ! array_key_exists($key_is_long_white, $t) or ! array_key_exists($key_is_short_white, $t) or ! array_key_exists($key_is_white_marubozu, $t) or
            ! array_key_exists($key_is_doji, $t) or ! array_key_exists($key_is_super_doji, $t)
        ) {
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                // TODO: check if bcmath is needed
                $h[$key_is_black] = (int) ($h['open'] > $h['close']);
                $h[$key_is_long_black] = (int) (($h['open'] > $h['close']) && ((($h['open'] - $h['close']) / (0.001 + $h['high'] - $h['low'])) > 0.6));
                $h[$key_is_short_black] = (int) (($h['open'] > $h['close']) && (($h['high'] - $h['low']) > (3 * ($h['open'] - $h['close']))));
                $h[$key_is_black_marubozu] = (int) (($h['open'] > $h['close']) & ($h['high'] == $h['open']) & ($h['close'] == $h['low']));
                $h[$key_is_white] = (int) ($h['close'] > $h['open']);
                $h[$key_is_long_white] = (int) (($h['close'] > $h['open']) && ((($h['close'] - $h['open']) / (0.001 + $h['high'] - $h['low'])) > 0.6));
                $h[$key_is_short_white] = (int) (($h['close'] > $h['open']) && (($h['high'] - $h['low']) > (3 * ($h['close'] - $h['open']))));
                $h[$key_is_white_marubozu] = (int) (($h['close'] > $h['open']) & ($h['high'] == $h['close']) & ($h['open'] == $h['low']));
                $h[$key_is_doji] = (int) ($h['open'] == $h['close']);
                $h[$key_is_super_doji] = (int) (($h['open'] == $h['close']) && (($h['high'] == $h['low'])));
                if ($debug) {
                    echo 'is_black() = '.$h[$key_is_black].PHP_EOL;
                    echo 'is_long_black() = '.$h[$key_is_long_black].PHP_EOL;
                    echo 'is_short_black() = '.$h[$key_is_short_black].PHP_EOL;
                    echo 'is_black_marubozu() = '.$h[$key_is_black_marubozu].PHP_EOL;
                    echo 'is_white() = '.$h[$key_is_white].PHP_EOL;
                    echo 'is_long_white() = '.$h[$key_is_long_white].PHP_EOL;
                    echo 'is_short_white() = '.$h[$key_is_short_white].PHP_EOL;
                    echo 'is_white_marubozu() = '.$h[$key_is_white_marubozu].PHP_EOL;
                    echo 'is_doji() = '.$h[$key_is_doji].PHP_EOL;
                    echo 'is_super_doji() = '.$h[$key_is_super_doji].PHP_EOL;
                }
            }
        }

        return [$key_is_black, $key_is_long_black, $key_is_short_black, $key_is_black_marubozu, $key_is_white, $key_is_long_white, $key_is_short_white, $key_is_white_marubozu, $key_is_doji, $key_is_super_doji];
    }

    // TODO: finish
    public function is_dragonfly(&$tickers)
    {
        throw new \LogicException(
            'Dragonfly pattern detection is unfinished and intentionally quarantined.'
        );
    }

    public function is_grave_stone(&$tickers)
    {
        throw new \LogicException(
            'Gravestone pattern detection is unfinished and intentionally quarantined.'
        );
    }

    public function is_inverted_hammer_or_shooting_star(&$tickers)
    {
        global $debug;

        if ($debug) {
            echo 'is_inverted_hammer(tickers)'.PHP_EOL;
        }

        $key_is_inverted_hammer = 'is_inverted_hammer()';
        $key_is_shooting_star = 'is_shooting_star()';
        $t = end($tickers);
        if (! array_key_exists($key_is_inverted_hammer, $t) or ! array_key_exists($key_is_shooting_star, $t)) {
            reset($tickers);
            foreach ($tickers as &$h) {      // Last element is the most rescent
                $h[$key_is_inverted_hammer] = (int) ((($h['high'] - $h['low']) > 3 * ($h['open'] - $h['close'])) && ((($h['high'] - $h['close']) / (0.001 + $h['high'] - $h['low'])) > 0.6) && ((($h['high'] - $h['open']) / (0.001 + $h['high'] - $h['low'])) > 0.6));
                $h[$key_is_shooting_star] = (int) ((($h['high'] - $h['low']) > 4 * ($h['open'] - $h['close'])) && ((($h['high'] - $h['close']) / (0.001 + $h['high'] - $h['low'])) >= 0.75) && ((($h['high'] - $h['open']) / (0.001 + $h['high'] - $h['low'])) >= 0.75));
                if ($debug) {
                    echo 'is_inverted_hammer() = '.$h[$key_is_inverted_hammer].PHP_EOL;
                    echo 'is_shooting_star() = '.$h[$key_is_shooting_star].PHP_EOL;
                }
            }
        }

        return [$key_is_inverted_hammer, $key_is_shooting_star];
    }
}
