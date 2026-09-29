<?php

namespace App\Console\Commands;

use App\Jobs\RecordMarketSignal;
use App\Models\MarketFeed;
use Illuminate\Console\Command;

final class DispatchMarketSignals extends Command
{
    protected $signature = 'trademinator:dispatch-market-signals';

    protected $description = 'Queue current signal recording once per actively subscribed market';

    public function handle(): int
    {
        if (! config('dashboard.signals_enabled') || ! config('intelligence.enabled')) {
            return self::SUCCESS;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Signal recording requires a persistent queue connection.');

            return self::FAILURE;
        }
        $count = 0;
        foreach (MarketFeed::query()->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->lazyById(100, column: 'market_id') as $feed) {
            dispatch((new RecordMarketSignal($feed->market_id))->onQueue(config('intelligence.queue')));
            $count++;
        }
        $this->info("Dispatched {$count} shared-market signal recordings.");

        return self::SUCCESS;
    }
}
