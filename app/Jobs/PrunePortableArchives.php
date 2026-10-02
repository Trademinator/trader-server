<?php

namespace App\Jobs;

use App\Domain\Archive\MultipartPortableArchive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PrunePortableArchives implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue((string) config('archive.portable_queue', 'archive'));
    }

    public function handle(MultipartPortableArchive $archive): void
    {
        $archive->pruneExpired();
    }
}
