<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

use function Trademinator\Time\periods_to_seconds;

final class CandleSyncPages
{
    public const DEFAULT_SIZE = 90;

    /** @return array{from: int, to: int, next: ?int} Inclusive second-based bounds. */
    public function window(int $from, int $to, string $period, int $size = self::DEFAULT_SIZE): array
    {
        if ($from > $to || $size < 10 || $size > 100 || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            throw new InvalidArgumentException('Provide a valid interval and supported period with a page size from 10 to 100 candles.');
        }

        $timeframe = new CandleTimeframe;
        $exclusive = $from * 1000;
        for ($i = 0; $i < $size; $i++) {
            $exclusive = $timeframe->next($exclusive, $period);
        }

        $nextBoundary = intdiv($exclusive, 1000);
        $end = min($to, $nextBoundary - 1);

        // Revisit a few previous candles on the next page so a gap crossing
        // the boundary can be inspected without fabricating OHLCV data.
        $next = $end === $to ? null : max($from + 1, $nextBoundary - 3 * periods_to_seconds($period));

        return ['from' => $from, 'to' => $end, 'next' => $next];
    }
}
