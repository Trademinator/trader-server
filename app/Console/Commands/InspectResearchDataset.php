<?php

namespace App\Console\Commands;

use App\Domain\Research\DatasetStore;
use Illuminate\Console\Command;
use Throwable;

final class InspectResearchDataset extends Command
{
    protected $signature = 'trademinator:dataset-info {dataset}';

    protected $description = 'Verify an M3 dataset checksum and display its frozen manifest';

    public function handle(DatasetStore $store): int
    {
        try {
            [$manifest] = $store->load($this->argument('dataset'));
            $this->line(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
