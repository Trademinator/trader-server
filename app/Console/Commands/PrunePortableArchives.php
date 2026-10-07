<?php

namespace App\Console\Commands;

use App\Domain\Archive\MultipartPortableArchive;
use Illuminate\Console\Command;

final class PrunePortableArchives extends Command
{
    protected $signature = 'trademinator:prune-portable-archives';

    protected $description = 'Delete expired temporary multipart portable archive transfers and files';

    public function handle(MultipartPortableArchive $archive): int
    {
        $count = $archive->pruneExpired();
        $this->info("Pruned {$count} expired portable archive transfer(s).");

        return self::SUCCESS;
    }
}
