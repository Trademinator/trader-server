<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\MarketData\CandleTimeframe;
use Illuminate\Console\Command;
use Throwable;

final class PredictMarketIntelligence extends Command
{
    protected $signature = 'trademinator:signal {exchange} {symbol} {period}';

    protected $description = 'Explain the latest closed-candle KNN signal and calibrated pattern probabilities';

    public function handle(MarketIntelligence $intelligence): int
    {
        try {
            (new CandleTimeframe)->next(0, $this->argument('period'));
            $this->line(json_encode($intelligence->predict($this->argument('exchange'), $this->argument('symbol'),
                $this->argument('period')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
