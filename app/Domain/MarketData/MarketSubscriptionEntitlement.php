<?php

namespace App\Domain\MarketData;

use App\Models\Exchange;
use App\Models\User;

interface MarketSubscriptionEntitlement
{
    public function canSubscribe(User $user, Exchange $exchange, string $symbol): bool;
}
