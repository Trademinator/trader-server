<?php

namespace App\Enums;

enum TradeAction: string
{
    case SELL = 'sell';
    case BUY = 'buy';
    case HODL = 'hodl';
/*
    public function cases(): array
    {
        return ['sell','buy','hodl'];
    }
*/
    public function color(): string
    {
         return match($this) {
            self::SELL => 'red',
            self::BUY => 'green',
            self::HODL => 'grey',
        };
    }
}
