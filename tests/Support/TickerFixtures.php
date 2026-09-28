<?php

namespace Tests\Support;

final class TickerFixtures
{
    /** Oscillating decimal strings, with Unix-second outer keys and exact ms fields. */
    public static function candles(int $count = 360): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $open = bcadd('100', bcdiv((string) (($i * 37) % 71 - 35), '17', 16), 16);
            $close = bcadd('100', bcdiv((string) (($i * 23) % 89 - 44), '19', 16), 16);
            $high = bcadd(bccomp($open, $close, 16) > 0 ? $open : $close, '1.1234567891234567', 16);
            $low = bcsub(bccomp($open, $close, 16) < 0 ? $open : $close, '1.2222222222222222', 16);
            $rows[1_700_000_000 + $i * 60] = [
                'microtimestamp' => 1_700_000_000_000 + $i * 60_000,
                'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
                'volume' => bcdiv((string) (($i * 17) % 53), '7', 16),
            ];
        }

        return $rows;
    }
}
