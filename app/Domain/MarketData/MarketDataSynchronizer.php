<?php

namespace App\Domain\MarketData;

use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use InvalidArgumentException;

final class MarketDataSynchronizer
{
    public function __construct(
        private readonly ExchangeRepository $exchanges,
        private readonly TickerRepository $tickers,
        private readonly CandleGaps $gaps,
    ) {}

    /** @return array{fetched: int, repaired: int, missing_ranges: int} */
    public function sync(string $exchange, string $symbol, string $period, int $from, int $to, bool $incremental = false, bool $repairGaps = false, int $requestLimit = 100): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('The start must not follow the end.');
        }

        $model = $this->exchanges->findByClass($exchange)?->first();
        if ($model === null) {
            throw new InvalidArgumentException("Exchange {$exchange} is not configured.");
        }

        $this->exchanges->setExchange($model);
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)
            || ! array_key_exists($period, $this->exchanges->periods()) || ! array_key_exists($symbol, $this->exchanges->markets())) {
            throw new InvalidArgumentException('The exchange does not support this symbol and period.');
        }

        $start = $from;
        if ($incremental) {
            $latest = $this->tickers->latestTimestamp($exchange, $symbol, $period);
            if ($latest !== null) {
                // Revisit the most recent stored candle: it may still be active.
                $start = max($from, intdiv($latest, 1000));
            }
        }

        $fetched = $start <= $to ? count($this->exchanges->fetch($symbol, $period, $start, $to, $requestLimit)) : 0;
        $timestamps = $this->tickers->timestamps($exchange, $symbol, $period, $from * 1000, $to * 1000);
        $missing = $this->gaps->between($timestamps, $period);
        $repaired = 0;

        if ($repairGaps) {
            // Limit repair calls within a page as well as the candle request.
            // Exchanges may omit no-trade candles altogether.
            foreach (array_slice($missing, 0, 5) as $gap) {
                $repaired += count($this->exchanges->fetch(
                    $symbol, $period, intdiv($gap['from'], 1000), intdiv($gap['to'], 1000), $requestLimit
                ));
            }
            $timestamps = $this->tickers->timestamps($exchange, $symbol, $period, $from * 1000, $to * 1000);
            $missing = $this->gaps->between($timestamps, $period);
        }

        return ['fetched' => $fetched, 'repaired' => $repaired, 'missing_ranges' => count($missing)];
    }
}
