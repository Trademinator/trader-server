<?php

namespace App\Console\Commands;

use App\Domain\Features\FeatureBuilder;
use App\Domain\MarketData\CandleTimeframe;
use Illuminate\Console\Command;

use function Trademinator\Time\to_unixtime;

/** Compatibility entry point: M2 replaces the old EMA-only debug command. */
class CreateIndicators extends Command
{
    protected $signature = 'trademinator:create-indicators {exchange} {symbol} {period} {from?} {to?} {--debug}';

    protected $description = 'Build M2 indicators and feature vectors from stored completed candles';

    public function handle(FeatureBuilder $builder): int
    {
        if (! in_array($this->argument('period'), CandleTimeframe::SUPPORTED, true)) {
            $this->error('Unsupported candle period.');

            return self::FAILURE;
        }
        $fromSeconds = $this->argument('from') === null ? null : to_unixtime($this->argument('from'));
        $toSeconds = $this->argument('to') === null ? null : to_unixtime($this->argument('to'));
        if ($fromSeconds === false || $toSeconds === false) {
            $this->error('Invalid start or end date.');

            return self::FAILURE;
        }
        $from = $fromSeconds === null ? null : $fromSeconds * 1000;
        $to = min((int) floor(microtime(true) * 1000), $toSeconds === null ? PHP_INT_MAX : $toSeconds * 1000);
        if ($from !== null && $from > $to) {
            $this->error('The start must precede the end.');

            return self::FAILURE;
        }
        $count = $builder->build($this->argument('exchange'), $this->argument('symbol'), $this->argument('period'), $to, $from);
        $this->info("Built {$count} M2 rows in market_features; source candles are unchanged.");

        return self::SUCCESS;
    }
}
