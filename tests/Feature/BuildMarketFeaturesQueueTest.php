<?php

use App\Domain\Features\FeatureEngine;
use App\Jobs\BuildMarketFeatures;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('queues feature work separately and only while the current feature version is behind', function () {
    config(['features.enabled' => true, 'features.queue' => 'features', 'archive.enabled' => false]);
    Queue::fake([BuildMarketFeatures::class]);

    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id,
        'active' => true,
    ]);

    $timestamp = 1_700_000_000_000;
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', [[
        'microtimestamp' => $timestamp,
        'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10',
    ]]);

    $this->artisan('trademinator:dispatch-market-features')
        ->expectsOutput('Dispatched 1 shared-market feature builds.')
        ->assertSuccessful();

    Queue::assertPushed(BuildMarketFeatures::class, function (BuildMarketFeatures $job): bool {
        return $job->exchange === 'kraken'
            && $job->symbol === 'BTC/USD'
            && $job->period === '1m'
            && $job->queue === 'features';
    });

    DB::table('market_features')->insert([
        'feature_id' => (string) Str::uuid7(),
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        'microtimestamp' => $timestamp,
        'available_at_ms' => $timestamp + 60_000,
        'version' => FeatureEngine::VERSION,
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Queue::fake([BuildMarketFeatures::class]);
    $this->artisan('trademinator:dispatch-market-features')
        ->expectsOutput('Dispatched 0 shared-market feature builds.')
        ->assertSuccessful();
    Queue::assertNothingPushed();
});
