<?php

namespace App\Console\Commands;

use App\Domain\MarketSuggestions\MarketDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RefreshMarketDiscovery extends Command
{
    protected $signature = 'trademinator:refresh-market-discovery';

    protected $description = 'Refresh bounded CoinGecko discovery and market conditions without subscribing or trading';

    public function handle(MarketDiscovery $discovery): int
    {
        $lock = Cache::lock('trademinator:market-discovery-refresh', 900);
        if (! $lock->get()) {
            $this->info('Discovery refresh is already running.');

            return self::SUCCESS;
        }
        try {
            $count = $discovery->refresh();
            $this->info("Refreshed {$count} unambiguous discovery assets.");

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Discovery refresh failed. The dashboard will fall back to preference screens when cached context expires.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
