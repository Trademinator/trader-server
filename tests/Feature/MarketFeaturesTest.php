<?php

use App\Domain\Features\CoinGeckoCollector;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureEngine;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function createContextMarket(string $exchangeClass, string $symbol, string $coinId, string $vsCurrency, ?string $category = null): Market
{
    $exchange = Exchange::query()->create(['name' => ucfirst($exchangeClass), 'class' => $exchangeClass, 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    CoinGeckoMarketMapping::query()->updateOrCreate(
        ['market_id' => $market->market_id],
        [
        'base_symbol' => strtoupper(explode('/', $symbol, 2)[0]),
        'vs_currency' => $vsCurrency,
        'coin_id' => $coinId,
        'category' => $category,
        'status' => 'resolved',
        'resolved_at' => now(),
        'last_error' => null,
    ]);

    return $market;
}

it('builds idempotently without modifying source candles or leaking future snapshots', function () {
    $start = 1700000000000;
    $candles = [];
    for ($i = 0; $i < 40; $i++) {
        $candles[] = ['microtimestamp' => $start + $i * 60000, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10'];
    }
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', $candles);
    $before = DB::table('tickers')->orderBy('microtimestamp')->pluck('payload')->all();
    createContextMarket('kraken', 'BTC/USD', 'bitcoin', 'usd');
    $id = (string) Str::uuid7();
    DB::table('market_context_snapshots')->insert(['snapshot_id' => $id, 'coin_id' => 'bitcoin', 'vs_currency' => 'usd',
        'observed_at_ms' => $start + 30 * 60000,
        'payload' => json_encode(['coin' => ['current_price' => 100], 'global' => []])]);
    $builder = app(FeatureBuilder::class);
    expect($builder->build('kraken', 'BTC/USD', '1m', $start + 40 * 60000))->toBe(40);
    $firstIds = DB::table('market_features')->orderBy('microtimestamp')->pluck('feature_id')->all();
    $builder->build('kraken', 'BTC/USD', '1m', $start + 40 * 60000);
    expect(DB::table('market_features')->count())->toBe(40)
        ->and(DB::table('market_features')->orderBy('microtimestamp')->pluck('feature_id')->all())->toBe($firstIds)
        ->and(DB::table('tickers')->orderBy('microtimestamp')->pluck('payload')->all())->toBe($before);
    $rows = MarketFeature::query()->orderBy('microtimestamp')->get();
    expect($rows[28]->payload['context_snapshot_id'])->toBeNull()
        ->and($rows[29]->payload['context_snapshot_id'])->toBe($id)
        ->and($rows[39]->payload['version'])->toBe(FeatureEngine::VERSION)
        ->and($rows[39]->payload['ready'])->toBeFalse();
});

it('batches resolved subscription mappings and records receipt time without duplicate hourly samples', function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
    createContextMarket('kraken', 'BTC/USD', 'bitcoin', 'usd');
    createContextMarket('coinbase', 'BTC/USD', 'bitcoin', 'usd');
    Http::preventStrayRequests();
    Http::fake([
        'api.coingecko.com/api/v3/global' => Http::response(['data' => ['updated_at' => time(), 'market_cap_percentage' => ['btc' => 50]]]),
        'api.coingecko.com/api/v3/coins/markets*' => Http::response([['id' => 'bitcoin', 'current_price' => 100,
            'market_cap' => 1000, 'total_volume' => 100, 'last_updated' => gmdate('c')]]),
    ]);
    $before = (int) floor(microtime(true) * 1000);
    expect(app(CoinGeckoCollector::class)->collect())->toBe(1)
        ->and(app(CoinGeckoCollector::class)->collect())->toBe(0)
        ->and((int) DB::table('market_context_snapshots')->value('observed_at_ms') >= $before)->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/coins/markets') && $r['ids'] === 'bitcoin' && $r->hasHeader('x-cg-demo-api-key', 'test-key'));
});

it('backfills and resolves CoinGecko mappings for active subscriptions', function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    CoinGeckoMarketMapping::query()->where('market_id', $market->market_id)->delete(); // Simulate a pre-migration subscription.

    Http::preventStrayRequests();
    Http::fake([
        'api.coingecko.com/api/v3/search*' => Http::response(['coins' => [[
            'id' => 'bitcoin', 'name' => 'Bitcoin', 'symbol' => 'btc', 'market_cap_rank' => 1,
        ]]]),
        'api.coingecko.com/api/v3/coins/bitcoin*' => Http::response(['categories' => ['Layer 1 (L1)']]),
        'api.coingecko.com/api/v3/coins/categories/list' => Http::response([[
            'category_id' => 'layer-1', 'name' => 'Layer 1 (L1)',
        ]]),
        'api.coingecko.com/api/v3/global' => Http::response(['data' => ['updated_at' => time(), 'market_cap_percentage' => ['btc' => 50]]]),
        'api.coingecko.com/api/v3/coins/categories' => Http::response([[
            'id' => 'layer-1', 'updated_at' => gmdate('c'), 'market_cap_change_24h' => 1.5,
        ]]),
        'api.coingecko.com/api/v3/coins/markets*' => Http::response([['id' => 'bitcoin', 'current_price' => 100,
            'market_cap' => 1000, 'total_volume' => 100, 'last_updated' => gmdate('c')]]),
    ]);

    expect(app(CoinGeckoCollector::class)->collect())->toBe(1);
    $mapping = CoinGeckoMarketMapping::query()->firstOrFail();
    expect($mapping->coin_id)->toBe('bitcoin')
        ->and($mapping->coin_name)->toBe('Bitcoin')
        ->and($mapping->category)->toBe('layer-1')
        ->and($mapping->status)->toBe('resolved');
});

it('marks ambiguous CoinGecko symbols instead of guessing a coin ID', function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
    $exchange = Exchange::query()->create(['name' => 'Demo', 'class' => 'demo', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'ABC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);

    Http::preventStrayRequests();
    Http::fake([
        'api.coingecko.com/api/v3/search*' => Http::response(['coins' => [
            ['id' => 'abc-one', 'name' => 'ABC One', 'symbol' => 'abc'],
            ['id' => 'abc-two', 'name' => 'ABC Two', 'symbol' => 'ABC'],
        ]]),
    ]);

    expect(app(CoinGeckoCollector::class)->collect())->toBe(0);
    $mapping = CoinGeckoMarketMapping::query()->firstOrFail();
    expect($mapping->status)->toBe('ambiguous')
        ->and($mapping->coin_id)->toBeNull();
    Http::assertSentCount(1);
});

it('does not substitute USD context for USDT or ignore provider errors', function () {
    createContextMarket('kraken', 'BTC/USDT', 'bitcoin', 'usd');
    expect(fn () => app(FeatureBuilder::class)->build('kraken', 'BTC/USDT', '1m'))->toThrow(RuntimeException::class);

    CoinGeckoMarketMapping::query()->delete();
    MarketSubscription::query()->delete();
    Market::query()->delete();
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
    Http::fake(['*' => Http::response([], 429)]);

    $exchange = Exchange::query()->where('class', 'kraken')->firstOrFail();
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    expect(fn () => app(CoinGeckoCollector::class)->collect())->toThrow(RequestException::class)
        ->and(DB::table('market_context_snapshots')->count())->toBe(0);
});

it('leaves context disabled by default', function () {
    config(['features.coingecko.enabled' => false]);
    Http::fake();
    expect(app(CoinGeckoCollector::class)->collect())->toBe(0);
    Http::assertNothingSent();
});
