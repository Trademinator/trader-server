<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ModelStore;
use Illuminate\Console\Command;
use Throwable;

final class InspectIntelligenceModel extends Command
{
    protected $signature = 'trademinator:model-info {model}';

    protected $description = 'Verify an intelligence artifact and show its schema, cutoffs and validation report';

    public function handle(ModelStore $models): int
    {
        try {
            $models->verify($this->argument('model'));
            $this->line(json_encode($models->report($this->argument('model')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
