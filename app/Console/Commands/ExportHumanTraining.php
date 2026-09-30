<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\HumanTrainingExport;
use Illuminate\Console\Command;
use Throwable;

class ExportHumanTraining extends Command
{
    protected $signature = 'trademinator:human-training-export {path : New private JSONL output file}';

    protected $description = 'Export versioned human training snapshots and reviews without account secrets';

    public function handle(HumanTrainingExport $export): int
    {
        try {
            $this->line(json_encode($export->write($this->argument('path')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
