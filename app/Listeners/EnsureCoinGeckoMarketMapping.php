<?php

namespace App\Listeners;

use App\Domain\Features\CoinGeckoMappingManager;
use App\Events\MarketSubscriptionCreated;

final class EnsureCoinGeckoMarketMapping
{
    public function __construct(private readonly CoinGeckoMappingManager $mappings) {}

    public function handle(MarketSubscriptionCreated $event): void
    {
        $market = $event->subscription->market()->first();
        if ($market !== null) {
            $this->mappings->ensureForMarket($market);
        }
    }
}
