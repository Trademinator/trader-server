<?php

namespace App\Domain\MarketData;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CandleTimeframe
{
    // Keep in sync with the tickers.period enum in the initial migration.
    public const SUPPORTED = ['1m', '3m', '5m', '15m', '30m', '45m', '1h', '2h', '3h', '4h', '6h', '8h', '12h', '1d', '3d', '7d', '1w', '2w', '1M', '3M', '4M', '1y'];

    public function next(int $timestampMilliseconds, string $period): int
    {
        if (! in_array($period, self::SUPPORTED, true)
            || ! preg_match('/^([1-9]\d*)([mhdwMy])$/D', $period, $matches)) {
            throw new InvalidArgumentException("Invalid candle period: {$period}");
        }

        $quantity = (int) $matches[1];
        $unit = $matches[2];

        if ($unit === 'M' || $unit === 'y') {
            $date = (new DateTimeImmutable('@'.intdiv($timestampMilliseconds, 1000)))
                ->setTimezone(new DateTimeZone('UTC'));
            $next = $date->modify('+'.$quantity.' '.($unit === 'M' ? 'months' : 'years'));

            return $next->getTimestamp() * 1000 + $timestampMilliseconds % 1000;
        }

        $seconds = match ($unit) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
        };

        return $timestampMilliseconds + $quantity * $seconds * 1000;
    }
}
