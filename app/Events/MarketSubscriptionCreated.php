<?php

namespace App\Events;

use App\Models\MarketSubscription;

final readonly class MarketSubscriptionCreated
{
    public function __construct(public MarketSubscription $subscription) {}
}
