<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Research\ResearchInput;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class BuildMarketIntelligence extends Command
{
    protected $signature = 'trademinator:knn-build {exchange} {symbol} {period}
        {--dataset=} {--schema=core} {--from=} {--to=} {--as-of=}';

    protected $description = 'Build closed-candle semantic knowledge and validate KNN and pattern intelligence';

    public function handle(MarketIntelligence $intelligence): int
    {
        try {
            if ($this->option('dataset') !== null && ($this->option('from') !== null
                || $this->option('to') !== null || $this->option('as-of') !== null || $this->option('schema') !== 'core')) {
                throw new InvalidArgumentException('--dataset cannot be combined with snapshot selection options.');
            }
            $report = $intelligence->build($this->argument('exchange'), $this->argument('symbol'), $this->argument('period'),
                $this->option('dataset'), $this->option('schema'),
                ResearchInput::timestamp($this->option('from')), ResearchInput::timestamp($this->option('to')),
                ResearchInput::timestamp($this->option('as-of')));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
