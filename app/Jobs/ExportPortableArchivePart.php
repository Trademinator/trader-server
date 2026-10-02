<?php

namespace App\Jobs;

use App\Domain\Archive\MultipartPortableArchive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ExportPortableArchivePart implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public array $backoff = [30, 120];

    public function __construct(public string $transferId, public int $sequence)
    {
        $this->onQueue((string) config('archive.portable_queue', 'archive'));
    }

    public function uniqueId(): string
    {
        return $this->transferId.'|'.$this->sequence;
    }

    public function handle(MultipartPortableArchive $archive): void
    {
        $archive->exportPart($this->transferId, $this->sequence);
    }

    public function failed(?Throwable $error): void
    {
        if ($error !== null) {
            app(MultipartPortableArchive::class)->failTransfer($this->transferId, $error);
        }
    }
}
