<?php

use App\Domain\MarketData\MarketFeedDispatcher;
use App\Domain\MarketData\MarketSubscriptions;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Illuminate\Support\Facades\Bus;

it('uses one feed for two users watching the same exchange and symbol, then idles on last unsubscribe', function () {
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->times(3)->with($exchange);
    $repository->shouldReceive('markets')->times(3)->andReturn(['BTC/USD' => []]);
    app()->instance(ExchangeRepository::class, $repository);
    $service = app(MarketSubscriptions::class);

    $a = $service->subscribe($alice, $exchange, 'BTC/USD', '0.01');
    $b = $service->subscribe($bob, $exchange, 'BTC/USD', '0.01');
    $service->subscribe($alice, $exchange, 'BTC/USD', '0.01');

    expect($a->market_id)->toBe($b->market_id)
        ->and(Market::query()->count())->toBe(1)
        ->and(MarketFeed::query()->count())->toBe(1)
        ->and(MarketSubscription::query()->count())->toBe(2);

    $market = Market::query()->firstOrFail();
    $service->unsubscribe($alice, $market);
    expect($market->feed()->first()->status)->not->toBe('idle');
    $service->unsubscribe($bob, $market);
    expect($market->feed()->first()->status)->toBe('idle')
        ->and($market->feed()->first()->next_pull_at)->toBeNull();
});

it('claims a due feed only once and ignores unsubscribed feeds', function () {
    Bus::fake();
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'status' => 'pending', 'next_pull_at' => now()->subMinute()]);
    $dispatcher = app(MarketFeedDispatcher::class);
    expect($dispatcher->dispatchDue())->toBe(0);

    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    expect($dispatcher->dispatchDue())->toBe(1)
        ->and($dispatcher->dispatchDue())->toBe(0);
    Bus::assertDispatchedTimes(CollectMarketFeed::class, 1);
    Bus::assertDispatched(CollectMarketFeed::class, fn (CollectMarketFeed $job): bool =>
        $job->marketId === $market->market_id && $job->leaseToken === MarketFeed::query()->first()->lease_token);
});
