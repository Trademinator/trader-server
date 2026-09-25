<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketFeedDispatcher;
use Illuminate\Console\Command;

final class DispatchMarketFeeds extends Command
{
    protected $signature = 'trademinator:dispatch-market-feeds {--limit=100}';

    protected $description = 'Queue due market feeds with at least one active subscription';

    public function handle(MarketFeedDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000 || in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Use a persistent queue connection and --limit between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Dispatched '.$dispatcher->dispatchDue($limit).' due market feeds.');

        return self::SUCCESS;
    }
}
