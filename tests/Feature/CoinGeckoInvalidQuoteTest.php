<?php

use App\Domain\Features\CoinGeckoCollector;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function quoteTestMarket(string $symbol, string $currency, string $status, ?string $coinId = null): void
{
    $exchange = Exchange::query()->firstOrCreate(['class' => 'quote-test'], ['name' => 'Quote Test', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    CoinGeckoMarketMapping::query()->create([
        'market_id' => $market->market_id, 'base_symbol' => explode('/', $symbol)[0],
        'vs_currency' => $currency, 'coin_id' => $coinId, 'status' => $status,
    ]);
}

beforeEach(function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
});

it('rejects unsupported exact quotes before resolving a mapping', function () {
    quoteTestMarket('BTC/XYZ', 'xyz', 'pending');
    Http::preventStrayRequests();
    Http::fake(['api.coingecko.com/api/v3/simple/supported_vs_currencies' => Http::response(['usd', 'cad'])]);

    expect(app(CoinGeckoCollector::class)->collect())->toBe(0);
    expect(CoinGeckoMarketMapping::query()->firstOrFail()->status)->toBe('unsupported');
    Http::assertSentCount(1);
});

it('isolates legacy invalid quotes and preserves collection for supported quotes', function () {
    quoteTestMarket('BTC/XYZ', 'xyz', 'resolved', 'bitcoin');
    quoteTestMarket('ATOM/USD', 'usd', 'resolved', 'cosmos');
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/global')) {
            return Http::response(['data' => ['updated_at' => time(), 'market_cap_percentage' => ['btc' => 50]]]);
        }
        if (str_contains($request->url(), '/coins/markets')) {
            return $request['vs_currency'] === 'xyz'
                ? Http::response(['error' => 'invalid vs_currency'], 400)
                : Http::response([['id' => 'cosmos', 'current_price' => 2,
                    'market_cap' => 1000, 'total_volume' => 100, 'last_updated' => gmdate('c')]]);
        }

        return Http::response([], 404);
    });

    expect(app(CoinGeckoCollector::class)->collect())->toBe(1);
    $this->assertDatabaseHas('coin_gecko_market_mappings', [
        'vs_currency' => 'xyz', 'status' => 'unsupported',
    ]);
    $this->assertDatabaseHas('market_context_snapshots', [
        'coin_id' => 'cosmos', 'vs_currency' => 'usd',
    ]);
});
