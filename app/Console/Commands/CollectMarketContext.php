<?php

namespace App\Console\Commands;

use App\Domain\Features\CoinGeckoCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CollectMarketContext extends Command
{
    protected $signature = 'trademinator:collect-market-context';

    protected $description = 'Collect timestamped CoinGecko context for subscribed markets';

    public function handle(CoinGeckoCollector $collector): int
    {
        $lock = Cache::lock('trademinator:coingecko-context', 3600);
        if (! $lock->get()) {
            $this->warn('Context collection is already running.');

            return self::SUCCESS;
        }
        try {
            $this->info('Stored '.$collector->collect().' context snapshots.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
