<?php

namespace App\Console\Commands;

use App\Jobs\TrainMarketIntelligence;
use App\Models\MarketFeed;
use Illuminate\Console\Command;

final class DispatchMarketIntelligence extends Command
{
    protected $signature = 'trademinator:dispatch-market-intelligence';

    protected $description = 'Queue weekly KNN and pattern training once per subscribed market and selected period';

    public function handle(): int
    {
        if (! config('intelligence.enabled')) {
            return self::SUCCESS;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Intelligence dispatch requires a persistent queue connection.');

            return self::FAILURE;
        }
        $count = 0;
        foreach (MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))->lazy(100) as $feed) {
            $job = new TrainMarketIntelligence($feed->market->exchange->class, $feed->market->symbol,
                $feed->selected_period, now()->startOfWeek()->format('Y-m-d'));
            $job->onQueue(config('intelligence.queue'));
            dispatch($job);
            $count++;
        }
        $this->info("Dispatched {$count} shared-market intelligence builds.");

        return self::SUCCESS;
    }
}
