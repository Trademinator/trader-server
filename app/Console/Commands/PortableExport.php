<?php

namespace App\Console\Commands;

use App\Domain\Archive\PortablePackage;
use Illuminate\Console\Command;

class PortableExport extends Command
{
    protected $signature = 'trademinator:portable-export {path} {--dataset=* : Logical datasets; currently tickers}';

    protected $description = 'Create a database-independent gzip JSONL export. Secrets are excluded.';

    public function handle(PortablePackage $portable): int
    {
        $datasets = $this->option('dataset') ?: ['tickers'];
        $result = $portable->export((string) $this->argument('path'), $datasets);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
