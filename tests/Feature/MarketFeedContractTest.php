<?php

use App\Domain\MarketData\AllowMarketSubscriptions;
use App\Domain\MarketData\MarketSubscriptionEntitlement;

it('has a separate billing entitlement seam for market subscriptions', function () {
    expect(app(MarketSubscriptionEntitlement::class))->toBeInstanceOf(AllowMarketSubscriptions::class);
});
