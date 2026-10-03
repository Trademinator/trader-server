<?php

use App\Domain\Intelligence\SignalFreshness;
use App\Domain\MarketData\CandleTimeframe;

it('expires at the earlier of the period window and wall clock cap', function () {
    $freshness = new SignalFreshness(new CandleTimeframe);
    $decision = strtotime('2026-10-01 00:00:00 UTC') * 1000;

    expect($freshness->expiresAt($decision, '1m', 2, 86400))->toBe($decision + 120000);
    expect($freshness->expiresAt($decision, '1w', 2, 86400))->toBe($decision + 86400000);
    expect($freshness->expiresAt($decision, '1M', 2, 86400))->toBe($decision + 86400000);
    expect($freshness->expiresAt(null, '1h', 2, 86400))->toBeNull();
    expect($freshness->expiresAt($decision, 'bad', 2, 86400))->toBeNull();
});
