<?php

namespace App\Console\Commands;

use App\Jobs\TrainMarketIntelligence;
use App\Models\MarketFeed;
use Illuminate\Console\Command;

final class DispatchLeadLagIntelligence extends Command
{
    protected $signature = 'trademinator:dispatch-lead-lag';

    protected $description = 'Queue daily lead/lag and downstream intelligence reevaluation for overlapping subscribed markets';

    public function handle(): int
    {
        if (! config('intelligence.enabled') || ! config('lead_lag.enabled') || ! config('lead_lag.daily_refresh')) {
            return self::SUCCESS;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Lead/lag dispatch requires a persistent queue connection.');

            return self::FAILURE;
        }
        $feeds = MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))->get();
        $count = 0;
        foreach ($feeds->groupBy(fn ($feed) => $feed->market->symbol.'|'.$feed->selected_period) as $group) {
            if ($group->pluck('market.exchange.class')->unique()->count() < 2) {
                continue;
            }
            foreach ($group as $feed) {
                $job = new TrainMarketIntelligence($feed->market->exchange->class, $feed->market->symbol,
                    $feed->selected_period, 'lead-lag:'.now()->format('Y-m-d'));
                dispatch($job->onQueue(config('intelligence.queue')));
                $count++;
            }
        }
        $this->info("Dispatched {$count} daily lead/lag intelligence builds.");

        return self::SUCCESS;
    }
}
