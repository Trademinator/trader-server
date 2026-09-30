<?php

namespace App\Console\Commands;

use App\Domain\Archive\TickerArchive;
use Illuminate\Console\Command;

class VerifyArchives extends Command
{
    protected $signature = 'trademinator:archive-verify {manifest? : Relative manifest path}';

    protected $description = 'Verify one or all archive shards, including checksum, row count and boundary keys.';

    public function handle(TickerArchive $archive): int
    {
        $manifest = $this->argument('manifest');
        $result = $manifest ? $archive->verifyManifest((string) $manifest) : $archive->verifyAll();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
