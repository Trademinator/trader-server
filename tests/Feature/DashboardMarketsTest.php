<?php

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\ModelStore;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function followedDashboardMarket(User $user, string $symbol, string $exchange, string $period): MarketSubscription
{
    $market = MarketSignal::factory()->create()->market;
    $market->update(['symbol' => $symbol]);
    $market->exchange->update(['name' => ucfirst($exchange), 'class' => $exchange]);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => $period, 'status' => 'ready']);

    return MarketSubscription::query()->create(['user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true]);
}

function dashboardModel(MarketSubscription $subscription, array $overrides = []): void
{
    $market = $subscription->market;
    $dataset = (string) Str::uuid();
    $model = (string) Str::uuid();
    $key = ModelStore::marketKey($market->exchange->class, $market->symbol, $market->feed->selected_period);
    DB::table('research_datasets')->insert(['dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => now()]);
    DB::table('intelligence_models')->insert(['model_id' => $model, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => 'ready', 'sha256' => str_repeat('0', 64), 'created_at' => now(),
        'report' => json_encode(array_replace(['model_id' => $model, 'dataset_id' => $dataset, 'status' => 'ready',
            'validation_version' => IntelligenceTrainer::VERSION, 'trained_as_of_ms' => now()->getTimestampMs()], $overrides))]);
    DB::table('intelligence_heads')->insert(['market_key' => $key, 'model_id' => $model, 'updated_at' => now()]);
}

it('counts current validated models across all followed markets regardless of page or search', function () {
    $this->freezeTime();
    config(['dashboard.page_size' => 1]);
    $user = User::factory()->create();
    $first = followedDashboardMarket($user, 'BTC/USD', 'kraken', '15m');
    dashboardModel($first);
    dashboardModel(followedDashboardMarket($user, 'ETH/USD', 'coinbase', '1h'), ['trained_as_of_ms' => now()->subDays(30)->getTimestampMs()]);
    dashboardModel(followedDashboardMarket($user, 'ADA/USD', 'bitso', '5m'), ['validation_version' => 'old']);
    dashboardModel(followedDashboardMarket(User::factory()->create(), 'PRIVATE/USD', 'kraken', '1m'));
    $inactive = followedDashboardMarket($user, 'OLD/USD', 'kraken', '1m');
    $inactive->update(['active' => false]);
    dashboardModel($inactive);

    foreach (['/dashboard', '/dashboard?page=2', '/dashboard?q=ETH', '/dashboard?q=no-match'] as $url) {
        $this->actingAs($user)->get($url)->assertViewHas('totals', ['followed' => 3, 'validated' => 1])
            ->assertSee('Across all markets you follow')->assertDontSee('For the markets on this page');
    }
});

it('searches partial pairs exchange names and periods across pages without leaking other subscriptions', function () {
    config(['dashboard.page_size' => 1]);
    $user = User::factory()->create();
    followedDashboardMarket($user, 'BTC/USD', 'kraken', '15m');
    followedDashboardMarket($user, 'ETH/USDT', 'coinbase', '1h');
    followedDashboardMarket(User::factory()->create(), 'PRIVATE/USDT', 'coinbase', '1h');

    foreach (['eth/us', 'OINB', '1H'] as $term) {
        $response = $this->actingAs($user)->getJson('/dashboard?q='.urlencode($term))->assertJsonPath('count', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($response->json('html'))->toContain('ETH/USDT')->not->toContain('BTC/USD', 'PRIVATE/USDT', 'Needs attention');
        expect($response->json())->not->toHaveKey('attention_count');
    }
    $this->getJson('/dashboard?q=%25')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=_')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=not-found')->assertJsonPath('count', 0);
    $this->getJson('/dashboard?q=USD&page=2')->assertJsonPath('count', 2);
    $this->get('/dashboard')->assertSee('data-dashboard-markets', false)->assertSee('dashboard-exchange-logo', false)
        ->assertSee('Search your markets');
});

it('exposes detailed collection errors and recovery commands only to the server owner', function () {
    $user = User::factory()->create();
    $sub = followedDashboardMarket($user, 'BTC/USD', 'kraken', '1m');
    $sub->market->feed->update(['status' => 'blocked', 'last_error' => '<script>private-diagnostic</script>']);
    $this->actingAs($user)->get('/dashboard')->assertDontSee('Needs attention')->assertDontSee('private-diagnostic')
        ->assertDontSee('trademinator:refresh-exchanges');
    $this->getJson('/dashboard')->assertJsonMissingPath('attention_count');
    config(['operations.owner_uuid' => strtoupper($user->getKey())]);
    $this->get('/dashboard')->assertSee('Needs attention')->assertSee('Collector blocked:')
        ->assertSee('trademinator:refresh-exchanges --check')->assertSee('&lt;script&gt;private-diagnostic', false)
        ->assertDontSee('<script>private-diagnostic</script>', false);
    $response = $this->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    expect($response->json('html'))->toContain('trademinator:refresh-exchanges --check');
    config(['operations.owner_uuid' => null]);
    expect($this->getJson('/dashboard')->json('html'))->not->toContain('private-diagnostic', 'Needs attention', 'queue:work');
});

it('does not flag healthy ready feeds or current in-progress collection as needing attention', function () {
    $this->travelTo('2026-09-30 04:15:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USD', 'kraken', '1m');
    Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'microtimestamp' => now()->subMinute()->getTimestampMs(),
        'payload' => json_encode(['open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '5'])]);
    $this->actingAs($user)->getJson('/dashboard')->assertJsonPath('attention_count', 0);
    $sub->market->feed->update(['status' => 'queued', 'lease_until' => now()->addMinutes(10)]);
    $this->getJson('/dashboard')->assertJsonPath('attention_count', 0);
    $sub->market->feed->update(['lease_until' => now()->subMinute()]);
    $response = $this->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    expect($response->json('html'))->toContain('lease expired', 'trademinator:dispatch-market-feeds');
});


it('collapses an expired queued retry and its dependent symptoms into one recovery item', function () {
    $this->travelTo('2026-10-01 11:30:00 UTC');
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USDC', 'ndax', '1m');
    $sub->market->feed->update([
        'selected_period' => null,
        'status' => 'queued',
        'lease_until' => now()->subMinute(),
        'last_error' => 'No candle period meets the quality, coverage and historical-depth thresholds yet.',
    ]);

    $response = $this->actingAs($user)->getJson('/dashboard')->assertJsonPath('attention_count', 1);
    $html = $response->json('html');

    expect($html)
        ->toContain('lease expired', 'Previous attempt:', 'No candle period meets the quality, coverage and historical-depth thresholds yet.')
        ->not->toContain('Collector queued:', 'No reliable candle period has been selected.');
    expect(substr_count($html, 'trademinator:dispatch-market-feeds'))->toBe(1)
        ->and(substr_count($html, 'queue:work --queue=default'))->toBe(1);
});

it('does not repeat a pending period-selection error as a second missing-period warning', function () {
    $user = User::factory()->create();
    config(['operations.owner_uuid' => $user->getKey()]);
    $sub = followedDashboardMarket($user, 'BTC/USDC', 'ndax', '1m');
    $sub->market->feed->update([
        'selected_period' => null,
        'status' => 'pending',
        'lease_until' => null,
        'last_error' => 'No candle period meets the quality, coverage and historical-depth thresholds yet.',
    ]);

    $html = $this->actingAs($user)->getJson('/dashboard')->assertJsonPath('attention_count', 1)->json('html');

    expect(substr_count($html, 'No candle period meets the quality, coverage and historical-depth thresholds yet.'))->toBe(1)
        ->and($html)->not->toContain('No reliable candle period has been selected.')
        ->and($html)->toContain('trademinator:dispatch-market-feeds');
});
