<?php

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\CoinGeckoReadiness;
use App\Domain\Intelligence\ModelStore;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00:30 UTC');
    config(['features.coingecko.enabled' => true, 'features.coingecko.max_age_seconds' => 7200,
        'intelligence.max_signal_age_periods' => 2, 'intelligence.max_signal_age_seconds' => 86400]);
});

function coinGeckoIndicatorFixture(string $symbol = 'BTC/USD'): array
{
    $exchange = Exchange::query()->firstOrCreate(['class' => 'kraken'], ['name' => 'Kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => $symbol, 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'ready']);
    $mapping = CoinGeckoMarketMapping::query()->create(['market_id' => $market->getKey(), 'base_symbol' => explode('/', $symbol)[0],
        'coin_id' => 'bitcoin', 'vs_currency' => 'usd', 'category' => 'currency', 'status' => 'resolved']);
    $snapshotId = (string) Str::uuid();
    $source = ['expires_at_ms' => now()->addHour()->getTimestampMs(),
        'category_expires_at_ms' => ['currency' => now()->addHour()->getTimestampMs()]];
    DB::table('market_context_snapshots')->insert(['snapshot_id' => $snapshotId, 'coin_id' => 'bitcoin', 'vs_currency' => 'usd',
        'observed_at_ms' => now()->subMinute()->getTimestampMs(), 'payload' => json_encode($source)]);
    $decision = now()->startOfMinute()->getTimestampMs();
    $featureId = (string) Str::uuid();
    $payload = ['version' => FeatureEngine::VERSION, 'available_at_ms' => $decision, 'context_snapshot_id' => $snapshotId,
        'context_ready' => true, 'features' => array_fill_keys(ContextFeatures::KEYS, 0.5)];
    DB::table('market_features')->insert(['feature_id' => $featureId, 'exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m',
        'version' => FeatureEngine::VERSION, 'available_at_ms' => $decision, 'microtimestamp' => $decision - 60000,
        'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
    $market->load('exchange', 'feed');
    $stream = ['exchange' => 'kraken', 'symbol' => $symbol, 'period' => '1m'];
    $key = ModelStore::marketKey('kraken', $symbol, '1m');

    return compact('market', 'mapping', 'snapshotId', 'source', 'featureId', 'payload', 'stream', 'key');
}

it('requires fresh complete context for the exact market while remaining independent of KNN validation', function (array $changes, bool $ready, string $reason) {
    $fixture = coinGeckoIndicatorFixture();
    config($changes['config'] ?? []);
    $fixture['mapping']->update($changes['mapping'] ?? []);
    $payload = array_replace_recursive($fixture['payload'], $changes['payload'] ?? []);
    $source = array_replace_recursive($fixture['source'], $changes['source'] ?? []);
    DB::table('market_features')->where('feature_id', $fixture['featureId'])
        ->update([...($changes['feature'] ?? []), 'payload' => json_encode($payload)]);
    DB::table('market_context_snapshots')->where('snapshot_id', $fixture['snapshotId'])
        ->update([...($changes['snapshot'] ?? []), 'payload' => json_encode($source)]);
    $stream = array_replace($fixture['stream'], $changes['stream'] ?? []);

    $state = app(CoinGeckoReadiness::class)->forStreams([$stream])->first();

    expect($state)->toBe(['ready' => $ready, 'reason' => $reason]);
    $view = $this->blade('<x-knn-readiness :coingecko="$context" />', ['context' => $state]);
    $view->assertSee('CoinGecko context: '.($ready ? 'Ready' : 'Not ready'))
        ->assertSee('Outcome KNN: Not ready')->assertSee('Action KNN: Not ready');
    expect(substr_count((string) $view, '>✓</span>'))->toBe((int) $ready);
})->with([
    'ready even without technical features or a trained model' => [[], true, 'ready'],
    'disabled collector' => [['config' => ['features.coingecko.enabled' => false]], false, 'coingecko_disabled'],
    'unresolved asset mapping' => [['mapping' => ['status' => 'pending']], false, 'coingecko_mapping_unresolved'],
    'wrong quote currency' => [['mapping' => ['vs_currency' => 'cad']], false, 'coingecko_quote_mismatch'],
    'no selected period' => [['stream' => ['period' => '']], false, 'coingecko_period_pending'],
    'different market' => [['stream' => ['symbol' => 'ETH/USD']], false, 'coingecko_mapping_unresolved'],
    'different period' => [['stream' => ['period' => '5m']], false, 'coingecko_features_unavailable'],
    'obsolete features' => [['feature' => ['version' => 'old']], false, 'coingecko_features_unavailable'],
    'future features' => [['feature' => ['available_at_ms' => 9999999999999]], false, 'coingecko_features_unavailable'],
    'stale latest candle' => [['feature' => ['available_at_ms' => 0]], false, 'coingecko_features_stale'],
    'missing context field despite ready flag' => [['payload' => ['features' => ['context.activity' => null]]], false, 'coingecko_context_incomplete'],
    'optional history and category fields can remain absent' => [['mapping' => ['category' => null],
        'payload' => ['features' => [
            'context.btc_dominance_change' => null, 'context.activity_deviation' => null,
            'context.category_momentum' => null, 'context.price_deviation' => null,
        ]]], true, 'ready'],
    'out of range context field' => [['payload' => ['features' => ['context.activity' => 2]]], false, 'coingecko_context_invalid'],
    'missing source snapshot' => [['payload' => ['context_snapshot_id' => null]], false, 'coingecko_snapshot_unavailable'],
    'source belongs to another coin' => [['snapshot' => ['coin_id' => 'ethereum']], false, 'coingecko_snapshot_unavailable'],
    'source observed after the candle' => [['snapshot' => ['observed_at_ms' => 9999999999999]], false, 'coingecko_context_stale'],
    'source exceeds maximum age' => [['snapshot' => ['observed_at_ms' => 0]], false, 'coingecko_context_stale'],
    'upstream data expired' => [['source' => ['expires_at_ms' => 1]], false, 'coingecko_context_stale'],
    'category data expired but core data fresh' => [['source' => ['category_expires_at_ms' => ['currency' => 1]]], true, 'ready'],
]);

it('checks a batch with three queries and uses the latest candle even when an older one is complete', function () {
    $first = coinGeckoIndicatorFixture();
    $second = coinGeckoIndicatorFixture('ETH/USD');
    $old = (array) DB::table('market_features')->where('feature_id', $first['featureId'])->first();
    DB::table('market_features')->insert([...$old, 'feature_id' => (string) Str::uuid(),
        'microtimestamp' => $old['microtimestamp'] - 60000, 'available_at_ms' => $old['available_at_ms'] - 60000]);
    $payload = $first['payload'];
    $payload['features']['context.activity'] = null;
    DB::table('market_features')->where('feature_id', $first['featureId'])->update(['payload' => json_encode($payload)]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $states = app(CoinGeckoReadiness::class)->forMarkets([$first['market'], $second['market']]);

    expect(DB::getQueryLog())->toHaveCount(3);
    DB::disableQueryLog();
    expect($states->get($first['key'])['ready'])->toBeFalse();
    expect($states->get($second['key'])['ready'])->toBeTrue();
});

it('shows yellow readiness consistently on market, dashboard, intelligence and owner displays', function () {
    $fixture = coinGeckoIndicatorFixture();
    $user = User::factory()->create();
    $subscription = MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $fixture['market']->getKey(), 'active' => true]);
    $this->actingAs($user);

    foreach (['/markets', '/dashboard', route('markets.intelligence', $subscription)] as $url) {
        $this->get($url)->assertSee('aria-label="CoinGecko context: Ready"', false)
            ->assertSee('knn-readiness-coingecko', false);
    }
    expect($this->getJson('/dashboard?q=BTC')->json('html'))->toContain('CoinGecko context: Ready');
    $this->get('/dashboard?q=no-match')->assertViewHas('totals', ['followed' => 1, 'outcome' => 0, 'action' => 0, 'coingecko' => 1])
        ->assertSee('CoinGecko context: 1 of 1 ready');

    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $fixture['key'],
        'status' => 'abstaining', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
        'report' => json_encode([...$fixture['stream'], 'status' => 'abstaining', 'reason' => 'no_eligible_k'])]);
    DB::table('intelligence_heads')->insert(['market_key' => $fixture['key'], 'model_id' => $model, 'updated_at' => now()]);
    config(['operations.owner_uuid' => $user->getKey()]);
    $this->withSession(['auth.password_confirmed_at' => time()]);
    foreach (['/owner/intelligence', '/owner/intelligence/'.$model] as $url) {
        $this->get($url)->assertSee('aria-label="CoinGecko context: Ready"', false);
    }
    $this->get('/owner')->assertViewHas('modelTotals', ['total' => 1, 'outcome' => 0, 'action' => 0, 'coingecko' => 1])
        ->assertSee('CoinGecko context: 1 of 1 ready');
});
