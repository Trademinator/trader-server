<?php

namespace App\Console\Commands;

use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\LabelDefinition;
use App\Domain\Research\ResearchInput;
use Illuminate\Console\Command;
use Throwable;

final class BuildResearchDataset extends Command
{
    protected $signature = 'trademinator:build-dataset {exchange} {symbol} {period}
        {--schema=core} {--features=} {--horizon=12} {--fee-bps=10} {--slippage-bps=5}
        {--min-return-bps=10} {--from=} {--to=} {--as-of=}';

    protected $description = 'Freeze M2 features and fee-aware future labels into an immutable M3 dataset';

    public function handle(DatasetSnapshotBuilder $builder): int
    {
        try {
            $definition = new LabelDefinition(ResearchInput::integer($this->option('horizon'), 'Horizon', 1, 10000),
                ResearchInput::bps($this->option('fee-bps')), ResearchInput::bps($this->option('slippage-bps')),
                ResearchInput::bps($this->option('min-return-bps')));
            $custom = $this->option('features') === null ? [] : array_map('trim', explode(',', $this->option('features')));
            $manifest = $builder->build($this->argument('exchange'), $this->argument('symbol'), $this->argument('period'),
                $definition, $this->option('schema'), $custom, ResearchInput::timestamp($this->option('from')),
                ResearchInput::timestamp($this->option('to')), ResearchInput::timestamp($this->option('as-of')));
            $this->line(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
