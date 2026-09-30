<?php

namespace App\Console\Commands;

use App\Domain\Archive\TickerArchive;
use Illuminate\Console\Command;

class RebuildArchiveCatalog extends Command
{
    protected $signature = 'trademinator:archive-rebuild-catalog';

    protected $description = 'Rebuild the active archive catalog by scanning and verifying filesystem manifests.';

    public function handle(TickerArchive $archive): int
    {
        $result = $archive->rebuildCatalog();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
