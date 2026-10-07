<?php

use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Str;

it('allows lock-contention releases without tolerating unlimited exceptions', function () {
    $job = new CollectMarketFeed((string) Str::uuid(), (string) Str::uuid());

    expect($job->tries)->toBe(20)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->timeout)->toBe(600)
        ->and($job->backoff)->toBe([30, 60, 120, 240]);
});

it('returns an exhausted collector to pending so the dispatcher can claim it again', function () {
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id,
        'symbol' => 'BTC/USD',
        'tick_size' => '0.01',
    ]);
    $leaseToken = (string) Str::uuid();
    $feed = MarketFeed::query()->create([
        'market_id' => $market->market_id,
        'status' => 'queued',
        'next_pull_at' => now()->subMinute(),
        'lease_token' => $leaseToken,
        'lease_until' => now()->addMinutes(15),
        'last_error' => null,
    ]);
    $before = now();

    (new CollectMarketFeed($market->market_id, $leaseToken))
        ->failed(new MaxAttemptsExceededException('Collector attempt budget exhausted.'));

    $feed = $feed->fresh();

    expect($feed->status)->toBe('pending')
        ->and($feed->lease_token)->toBeNull()
        ->and($feed->lease_until)->toBeNull()
        ->and($feed->last_error)->toBe('Market feed remained busy; collection will be retried.')
        ->and($feed->next_pull_at->betweenIncluded($before->copy()->addSeconds(55), $before->copy()->addSeconds(65)))->toBeTrue();
});
