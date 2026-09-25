<?php

use App\Domain\Features\CoinGeckoCollector;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureEngine;
use App\Models\MarketFeature;
use App\Repositories\TickerRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('builds idempotently without modifying source candles or leaking future snapshots', function () {
    $start = 1700000000000;
    $candles = [];
    for ($i = 0; $i < 40; $i++) {
        $candles[] = ['microtimestamp' => $start + $i * 60000, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10'];
    }
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', $candles);
    $before = DB::table('tickers')->orderBy('microtimestamp')->pluck('payload')->all();
    config(['features.coingecko.markets' => ['kraken:BTC/USD' => ['id' => 'bitcoin', 'vs_currency' => 'usd']]]);
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

it('uses explicit IDs batches coins and records receipt time without duplicate hourly samples', function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key',
        'features.coingecko.markets' => ['kraken:BTC/USD' => ['id' => 'bitcoin', 'vs_currency' => 'usd'],
            'coinbase:BTC/USD' => ['id' => 'bitcoin', 'vs_currency' => 'usd']]]);
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

it('does not substitute USD context for USDT or ignore provider errors', function () {
    config(['features.coingecko.markets' => ['kraken:BTC/USDT' => ['id' => 'bitcoin', 'vs_currency' => 'usd']]]);
    expect(fn () => app(FeatureBuilder::class)->build('kraken', 'BTC/USDT', '1m'))->toThrow(RuntimeException::class);
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
    Http::fake(['*' => Http::response([], 429)]);
    expect(fn () => app(CoinGeckoCollector::class)->collect())->toThrow(RequestException::class)
        ->and(DB::table('market_context_snapshots')->count())->toBe(0);
});

it('leaves context disabled by default', function () {
    config(['features.coingecko.enabled' => false]);
    Http::fake();
    expect(app(CoinGeckoCollector::class)->collect())->toBe(0);
    Http::assertNothingSent();
});
