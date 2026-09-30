<?php

namespace App\Console\Commands;

use App\Domain\Archive\TickerArchive;
use Illuminate\Console\Command;

class ArchiveTickers extends Command
{
    protected $signature = 'trademinator:archive-tickers {exchange} {symbol} {period} {month : YYYY-MM} {--dry-run}';

    protected $description = 'Export one immutable monthly ticker shard to verified cold storage without pruning hot rows.';

    public function handle(TickerArchive $archive): int
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/D', (string) $this->argument('month'), $match)) {
            $this->error('Month must use YYYY-MM.');

            return self::FAILURE;
        }
        $result = $archive->archiveMonth((string) $this->argument('exchange'), (string) $this->argument('symbol'),
            (string) $this->argument('period'), (int) $match[1], (int) $match[2], (bool) $this->option('dry-run'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
