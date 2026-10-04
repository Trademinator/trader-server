<?php

namespace App\Console\Commands;

use App\Domain\MarketEvents\GdeltMarketEventCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CollectGdeltMarketEvents extends Command
{
    protected $signature = 'trademinator:collect-market-events {--force : Reprocess the latest GDELT GKG batch}';

    protected $description = 'Discover GDELT GKG fork-related event candidates for owner review';

    public function handle(GdeltMarketEventCollector $collector): int
    {
        if (! config('gdelt.enabled')) {
            $this->info('GDELT event discovery is disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('trademinator:gdelt-market-events', 900);
        if (! $lock->get()) {
            $this->warn('GDELT event discovery is already running.');

            return self::SUCCESS;
        }
        try {
            try {
                $stored = $collector->collect((bool) $this->option('force'));
            } catch (Throwable $error) {
                report($error);
                $this->error('GDELT event discovery failed: '.$error->getMessage());

                return self::FAILURE;
            }
            $this->info('Stored or refreshed '.$stored.' GDELT event candidates.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
