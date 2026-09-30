<?php

namespace App\Console\Commands;

use App\Domain\Archive\PortablePackage;
use Illuminate\Console\Command;

class PortableImport extends Command
{
    protected $signature = 'trademinator:portable-import {path} {--validate-only}';

    protected $description = 'Validate or import a portable gzip JSONL package without silently overwriting conflicts.';

    public function handle(PortablePackage $portable): int
    {
        $result = $portable->import((string) $this->argument('path'), (bool) $this->option('validate-only'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
