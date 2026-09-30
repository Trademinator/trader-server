<?php

namespace App\Console\Commands;

use App\Domain\Archive\TickerArchive;
use Illuminate\Console\Command;

class RestoreArchive extends Command
{
    protected $signature = 'trademinator:archive-restore {manifest : Relative manifest path} {--from= : Inclusive millisecond timestamp} {--to= : Inclusive millisecond timestamp} {--validate-only}';

    protected $description = 'Validate or restore one verified archive shard into hot storage without overwriting conflicts.';

    public function handle(TickerArchive $archive): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $result = $archive->restoreManifest((string) $this->argument('manifest'), (bool) $this->option('validate-only'),
            $from === null ? null : (int) $from, $to === null ? null : (int) $to);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
