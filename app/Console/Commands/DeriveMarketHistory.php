<?php

namespace App\Console\Commands;

use App\Domain\Features\DerivedMarketHistory;
use App\Domain\Research\ResearchInput;
use Illuminate\Console\Command;
use Throwable;

final class DeriveMarketHistory extends Command
{
    protected $signature = 'trademinator:derive-timeframe {exchange} {symbol} {base} {period} {--from=} {--as-of=}';

    protected $description = 'Derive complete larger candles from the selected base period and reuse the M2 feature pipeline';

    public function handle(DerivedMarketHistory $history): int
    {
        try {
            $this->line(json_encode($history->build($this->argument('exchange'), $this->argument('symbol'),
                $this->argument('base'), $this->argument('period'), ResearchInput::timestamp($this->option('from')),
                ResearchInput::timestamp($this->option('as-of'))), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
