<?php

namespace App\Console\Commands;

use App\Domain\Features\FeatureBuilder;
use App\Domain\MarketData\CandleTimeframe;
use Illuminate\Console\Command;

final class BuildMarketFeatures extends Command
{
    protected $signature = 'trademinator:build-features {exchange} {symbol} {period}';

    protected $description = 'Replay stored completed candles into versioned, causal M2 features';

    public function handle(FeatureBuilder $builder): int
    {
        if (! in_array($this->argument('period'), CandleTimeframe::SUPPORTED, true)) {
            $this->error('Unsupported candle period.');

            return self::FAILURE;
        }
        $count = $builder->build($this->argument('exchange'), $this->argument('symbol'), $this->argument('period'));
        $this->info("Built {$count} feature rows. Missing context and warm-up values remain null.");

        return self::SUCCESS;
    }
}
