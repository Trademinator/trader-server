<?php

namespace App\Console\Commands;

use App\Jobs\BuildMarketFeatures;
use App\Models\MarketFeed;
use Illuminate\Console\Command;

final class DispatchMarketFeatures extends Command
{
    protected $signature = 'trademinator:dispatch-market-features';

    protected $description = 'Queue M2 feature builds for subscribed markets with selected candle periods';

    public function handle(): int
    {
        if (! config('features.enabled')) {
            return self::SUCCESS;
        }
        $count = 0;
        foreach (MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))->lazy(100) as $feed) {
            BuildMarketFeatures::dispatch($feed->market->exchange->class, $feed->market->symbol, $feed->selected_period);
            $count++;
        }
        $this->info("Dispatched {$count} shared-market feature builds.");

        return self::SUCCESS;
    }
}
