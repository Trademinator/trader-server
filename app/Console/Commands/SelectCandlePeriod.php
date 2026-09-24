<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandleQuality;
use App\Repositories\ExchangeRepository;
use Illuminate\Console\Command;
use InvalidArgumentException;
use function Trademinator\Time\periods_to_seconds;
use function Trademinator\Time\to_unixtime;

class SelectCandlePeriod extends Command
{
    protected $signature = 'trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--minimum=50} {--from=7 days ago} {--to=now}';

    protected $description = 'Select the shortest sufficiently informative candle period';

    public function handle(ExchangeRepository $repository, CandleQuality $quality): int
    {
        $exchange = $repository->findByClass((string) $this->argument('exchange'))?->first();
        if ($exchange === null) {
            $this->error('Exchange is not configured.');
            return self::FAILURE;
        }
        $tickSize = (float) $this->option('tick-size');
        $from = to_unixtime((string) $this->option('from'));
        $to = to_unixtime((string) $this->option('to'));
        if ($tickSize <= 0 || $from === false || $to === false || $from >= $to) {
            $this->error('Provide a positive --tick-size and a valid time interval.');
            return self::FAILURE;
        }
        $repository->setExchange($exchange);
        $supported = array_keys($repository->periods());
        $periods = array_map('trim', explode(',', (string) $this->option('periods')));
        $periods = array_values(array_unique($periods));
        if (in_array('', $periods, true) || array_diff($periods, $supported)) {
            $this->error('All requested periods must be supported by the exchange.');
            return self::FAILURE;
        }
        usort($periods, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
        $samples = [];
        foreach ($periods as $period) {
            $samples[$period] = $repository->fetch((string) $this->argument('symbol'), $period, $from, $to);
        }
        try {
            $selected = $quality->choose($samples, $tickSize, (float) $this->option('threshold'), (int) $this->option('minimum'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        if ($selected === null) {
            $this->warn('No sampled timeframe meets the candle-quality threshold.');
            return self::FAILURE;
        }
        $this->line(json_encode($selected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
