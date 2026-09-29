<?php

use App\Domain\MarketSuggestions\MarketDiscovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->freezeTime();
    config(['dashboard.discovery_enabled' => true, 'features.coingecko.enabled' => true,
        'features.coingecko.api_key' => 'fixture-key', 'dashboard.discovery_limit' => 2]);
    Http::preventStrayRequests();
});

it('caches bounded fresh context, rejects ambiguous symbols and never starts collection', function () {
    Http::fake(function ($request) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/v3/global' => Http::response(['data' => ['updated_at' => now()->timestamp,
                'market_cap_change_percentage_24h_usd' => 2, 'market_cap_percentage' => ['btc' => 55], 'total_volume' => ['usd' => 500]]]),
            '/api/v3/coins/markets' => Http::response([
                ['id' => 'bitcoin', 'symbol' => 'btc', 'total_volume' => 200, 'market_cap' => 1000, 'last_updated' => now()->toIso8601String(), 'price_change_percentage_24h' => 1],
                ['id' => 'duplicate', 'symbol' => 'dup', 'total_volume' => 900, 'market_cap' => 1000, 'last_updated' => now()->toIso8601String()],
                ['id' => 'stale', 'symbol' => 'old', 'total_volume' => 1000, 'market_cap' => 1000, 'last_updated' => now()->subHours(3)->toIso8601String()],
                ['id' => 'invalid', 'symbol' => 'bad', 'total_volume' => 1000, 'market_cap' => 0, 'last_updated' => now()->toIso8601String()],
            ]),
            '/api/v3/search' => Http::response(['coins' => $request['query'] === 'BTC' ? [['id' => 'bitcoin', 'symbol' => 'btc']]
                : [['id' => 'duplicate', 'symbol' => 'dup'], ['id' => 'another', 'symbol' => 'DUP']]]),
            '/api/v3/coins/categories' => Http::response([
                ['name' => 'Recent category', 'market_cap_change_24h' => -3, 'updated_at' => now()->subSeconds(7190)->toIso8601String()],
                ['name' => 'Stale category', 'market_cap_change_24h' => 100, 'updated_at' => now()->subHours(3)->toIso8601String()],
            ]),
        };
    });
    $this->artisan('trademinator:refresh-market-discovery')->assertSuccessful();
    $snapshot = app(MarketDiscovery::class)->snapshot();
    expect(array_keys($snapshot['coins']))->toBe(['BTC'])
        ->and($snapshot['coins']['BTC']['activity_ratio'])->toBe(0.2)
        ->and(array_column($snapshot['categories'], 'name'))->toBe(['Recent category']);
    Http::assertSentCount(5);
    Http::assertSent(fn ($request) => $request->hasHeader('x-cg-demo-api-key', 'fixture-key'));
    foreach (['markets', 'market_feeds', 'market_subscriptions', 'market_context_snapshots'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->travel(11)->seconds();
    expect(app(MarketDiscovery::class)->snapshot())->toBeNull();
});

it('preserves usable cached context on an outage and stops using it after its source expires', function () {
    $snapshot = ['expires_at_ms' => now()->addMinute()->getTimestampMs(), 'coins' => ['BTC' => ['coin_id' => 'bitcoin']]];
    Cache::put(MarketDiscovery::CACHE_KEY, $snapshot, 7200);
    Http::fake(['*' => Http::response([], 429)]);
    $this->artisan('trademinator:refresh-market-discovery')->assertFailed();
    expect(app(MarketDiscovery::class)->snapshot())->toBe($snapshot);
    $this->travel(61)->seconds();
    expect(app(MarketDiscovery::class)->snapshot())->toBeNull();
});

it('does not request discovery when disabled and refuses missing credentials or stale global context', function () {
    Http::fake();
    config(['dashboard.discovery_enabled' => false]);
    expect(app(MarketDiscovery::class)->refresh())->toBe(0)->and(app(MarketDiscovery::class)->snapshot())->toBeNull();
    config(['dashboard.discovery_enabled' => true, 'features.coingecko.api_key' => null]);
    $this->artisan('trademinator:refresh-market-discovery')->assertFailed();
    Http::assertNothingSent();
    config(['features.coingecko.api_key' => 'fixture-key']);
    Http::fake(['*' => Http::response(['data' => ['updated_at' => now()->subHours(3)->timestamp]])]);
    $this->artisan('trademinator:refresh-market-discovery')->assertFailed();
    expect(app(MarketDiscovery::class)->snapshot())->toBeNull();
    Http::assertSentCount(1);
});
