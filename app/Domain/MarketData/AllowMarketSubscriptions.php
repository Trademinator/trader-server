<?php

namespace App\Domain\MarketData;

use App\Models\Exchange;
use App\Models\User;

final class AllowMarketSubscriptions implements MarketSubscriptionEntitlement
{
    public function canSubscribe(User $user, Exchange $exchange, string $symbol): bool
    {
        return true;
    }
}
