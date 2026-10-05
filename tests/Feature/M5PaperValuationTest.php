<?php

use App\Models\ClientApiKey;
use App\Models\ClientMarketSetting;
use App\Models\ClientPaperAccount;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['archive.enabled' => false]);
});

function f13PaperAccount(User $user): array
{
    $secret = 'tmk_'.Str::random(43);
    ClientApiKey::query()->create([
        'user_id' => $user->getKey(), 'label' => 'paper valuation', 'prefix' => substr($secret, 0, 12),
        'secret_hash' => hash('sha256', $secret),
    ]);
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->getKey(), 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1h', 'status' => 'active']);
    $subscription = MarketSubscription::query()->create([
        'user_id' => $user->getKey(), 'market_id' => $market->getKey(), 'active' => true,
    ]);
    $account = ClientPaperAccount::query()->create([
        'user_id' => $user->getKey(), 'market_subscription_id' => $subscription->getKey(),
        'quote_balance' => '900', 'base_balance' => '1', 'initial_quote_balance' => '1000',
        'benchmark_base_quantity' => '10', 'benchmark_start_price' => '100',
        'peak_equity' => '1000', 'realized_fees_quote' => '0',
        'started_at_ms' => now()->subDay()->getTimestampMs(),
    ]);

    return [$secret, $subscription, $account];
}

function f13Candle(int $openedAtMs, string $close): void
{
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1h', [[
        'microtimestamp' => $openedAtMs, 'open' => $close, 'high' => $close,
        'low' => $close, 'close' => $close, 'volume' => '1',
    ]]);
}

function f13Signal(MarketSubscription $subscription, mixed $price, int $observedAtMs, string $period = '1h'): void
{
    MarketSignal::query()->create([
        'market_id' => $subscription->market_id, 'snapshot_key' => hash('sha256', Str::uuid()),
        'period' => $period, 'model_id' => null, 'decision_at_ms' => $observedAtMs,
        'recorded_at_ms' => now()->getTimestampMs(), 'is_change' => false,
        'action' => 'hodl', 'reason' => 'missing_model',
        'payload' => ['reference_price' => $price, 'reference_price_source' => 'closed_candle_close'],
    ]);
}

it('values a paper account at the latest closed candle without a supplied bid', function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 30));
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    f13Signal($subscription, '100', now()->subHours(2)->getTimestampMs());
    f13Candle(now()->setTime(10, 0)->getTimestampMs(), '120');
    f13Candle(now()->setTime(11, 0)->getTimestampMs(), '150');
    f13Candle(now()->setTime(12, 0)->getTimestampMs(), '9999');

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey().'/paper')
        ->assertOk()->assertJsonPath('paper.equity_quote', '1050.000000000000000000')
        ->assertJsonPath('paper.return_pct', 5)->assertJsonPath('paper.drawdown_pct', 0)
        ->assertJsonPath('paper.benchmark.equity_quote', '1500.000000000000000000')
        ->assertJsonPath('paper.benchmark.return_pct', 50)
        ->assertJsonPath('paper.valuation.price', '150')
        ->assertJsonPath('paper.valuation.source', 'closed_candle_close')
        ->assertJsonPath('paper.valuation.observed_at_ms', now()->setTime(12, 0)->getTimestampMs())
        ->assertJsonPath('paper.valuation.age_seconds', 1800);

    $this->assertDatabaseHas('client_paper_accounts', ['client_paper_account_id' => $account->getKey(), 'peak_equity' => 1050]);
    $this->assertDatabaseCount('client_paper_events', 0);
});

it('retains peaks observed by GET and measures a later drawdown without a paper trade', function () {
    $this->freezeTime();
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    f13Candle(now()->subHours(2)->getTimestampMs(), '150');
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';

    $this->withToken($secret)->getJson($url.'?best_bid=200')
        ->assertOk()->assertJsonPath('paper.equity_quote', '1100.000000000000000000')
        ->assertJsonPath('paper.return_pct', 10)->assertJsonPath('paper.drawdown_pct', 0)
        ->assertJsonPath('paper.valuation.source', 'client_bid');
    $this->getJson($url.'?best_bid=100')
        ->assertOk()->assertJsonPath('paper.equity_quote', '1000.000000000000000000')
        ->assertJsonPath('paper.drawdown_pct', 9.09090909);

    $this->assertDatabaseHas('client_paper_accounts', [
        'client_paper_account_id' => $account->getKey(), 'peak_equity' => 1100,
        'quote_balance' => 900, 'base_balance' => 1, 'realized_fees_quote' => 0,
    ]);
    $this->assertDatabaseCount('client_paper_events', 0);
});

it('uses a newer recorded signal price when available', function (bool $hasCandle) {
    $this->freezeTime();
    [$secret, $subscription] = f13PaperAccount(User::factory()->create());
    if ($hasCandle) {
        f13Candle(now()->subHours(2)->getTimestampMs(), '130');
    }
    f13Signal($subscription, '140', now()->subMinutes(15)->getTimestampMs());

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey().'/paper')
        ->assertOk()->assertJsonPath('paper.equity_quote', '1040.000000000000000000')
        ->assertJsonPath('paper.return_pct', 4)
        ->assertJsonPath('paper.valuation.source', 'signal_reference_price')
        ->assertJsonPath('paper.valuation.observed_at_ms', now()->subMinutes(15)->getTimestampMs());
})->with(['without candles' => false, 'with an older candle' => true]);

it('retains a newer client valuation until a more recent candle is available', function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 30));
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    f13Candle(now()->setTime(11, 0)->getTimestampMs(), '150');
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    $this->withToken($secret)->getJson($url.'?best_bid=200')->assertOk();

    $this->getJson($url)->assertOk()
        ->assertJsonPath('paper.equity_quote', '1100.000000000000000000')
        ->assertJsonPath('paper.drawdown_pct', 0)->assertJsonPath('paper.valuation.source', 'client_bid');
    $this->travel(30)->minutes();
    f13Candle(now()->setTime(12, 0)->getTimestampMs(), '180');
    $this->getJson($url)->assertOk()
        ->assertJsonPath('paper.equity_quote', '1080.000000000000000000')
        ->assertJsonPath('paper.drawdown_pct', 1.81818181)
        ->assertJsonPath('paper.valuation.source', 'closed_candle_close');

    $this->assertDatabaseHas('client_paper_accounts', [
        'client_paper_account_id' => $account->getKey(), 'peak_equity' => 1100,
        'valuation_price' => 180, 'valuation_at_ms' => now()->getTimestampMs(),
    ]);
    $this->assertDatabaseCount('client_paper_events', 0);
});

it('reports unavailable valuation instead of using the benchmark entry price', function (mixed $price, int $offsetMinutes, string $period) {
    $this->freezeTime();
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    f13Signal($subscription, $price, now()->addMinutes($offsetMinutes)->getTimestampMs(), $period);
    f13Candle(now()->getTimestampMs(), '200');

    $this->withToken($secret)->getJson('/api/v1/client/markets/'.$subscription->getKey().'/paper')
        ->assertOk()->assertJsonPath('paper.equity_quote', null)
        ->assertJsonPath('paper.return_pct', null)->assertJsonPath('paper.drawdown_pct', null)
        ->assertJsonPath('paper.benchmark.equity_quote', null)->assertJsonPath('paper.benchmark.return_pct', null)
        ->assertJsonPath('paper.valuation.price', null)->assertJsonPath('paper.valuation.source', 'unavailable');

    $this->assertDatabaseHas('client_paper_accounts', ['client_paper_account_id' => $account->getKey(), 'peak_equity' => 1000]);
    $this->assertDatabaseCount('client_paper_events', 0);
})->with([
    'missing price' => [null, -15, '1h'],
    'invalid price' => ['NaN', -15, '1h'],
    'zero price' => ['0', -15, '1h'],
    'future observation' => ['150', 15, '1h'],
    'different period' => ['150', -15, '15m'],
]);

it('refreshes a corrected candle price with the same observation timestamp', function () {
    $this->freezeTime();
    [$secret, $subscription] = f13PaperAccount(User::factory()->create());
    $openedAt = now()->subHours(2)->getTimestampMs();
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    f13Candle($openedAt, '130');
    $this->withToken($secret)->getJson($url)->assertOk()->assertJsonPath('paper.return_pct', 3);

    f13Candle($openedAt, '150');
    $this->getJson($url)->assertOk()->assertJsonPath('paper.return_pct', 5)
        ->assertJsonPath('paper.valuation.source', 'closed_candle_close');

    $this->assertDatabaseHas('client_paper_accounts', [
        'market_subscription_id' => $subscription->getKey(), 'valuation_price' => 150, 'peak_equity' => 1050,
    ]);
});

it('does not replace a newer saved valuation with an older POST quote', function () {
    $this->freezeTime();
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    ClientMarketSetting::query()->create([
        'user_id' => $subscription->user_id, 'market_subscription_id' => $subscription->getKey(), 'paper_enabled' => true,
    ]);
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    $this->withToken($secret)->getJson($url.'?best_bid=200')->assertOk();

    $this->postJson($url, [
        'idempotency_key' => 'paper-f13-older', 'reported_at_ms' => now()->subSeconds(10)->getTimestampMs(),
        'best_bid' => 100, 'best_ask' => 100, 'taker_fee_bps' => 0,
    ])->assertOk()->assertJsonPath('event', 'skipped')
        ->assertJsonPath('paper.equity_quote', '1100.000000000000000000')
        ->assertJsonPath('paper.drawdown_pct', 0)
        ->assertJsonPath('paper.valuation.observed_at_ms', now()->getTimestampMs());

    $this->assertDatabaseHas('client_paper_accounts', [
        'client_paper_account_id' => $account->getKey(), 'valuation_price' => 200,
        'valuation_at_ms' => now()->getTimestampMs(), 'quote_balance' => 900, 'base_balance' => 1,
    ]);
    $this->assertDatabaseCount('client_paper_events', 1);
});

it('preserves POST replay results after GET records a higher equity peak', function () {
    $this->freezeTime();
    [$secret, $subscription, $account] = f13PaperAccount(User::factory()->create());
    ClientMarketSetting::query()->create([
        'user_id' => $subscription->user_id, 'market_subscription_id' => $subscription->getKey(), 'paper_enabled' => true,
    ]);
    $url = '/api/v1/client/markets/'.$subscription->getKey().'/paper';
    $input = ['idempotency_key' => 'paper-f13-replay', 'reported_at_ms' => now()->getTimestampMs(),
        'best_bid' => 100, 'best_ask' => 100, 'taker_fee_bps' => 0];
    $original = $this->withToken($secret)->postJson($url, $input)->assertOk()->json();

    $this->getJson($url.'?best_bid=200')->assertOk()->assertJsonPath('paper.drawdown_pct', 0);
    $this->postJson($url, $input)->assertOk()->assertExactJson($original);

    $this->assertDatabaseHas('client_paper_accounts', ['client_paper_account_id' => $account->getKey(), 'peak_equity' => 1100]);
    $this->assertDatabaseCount('client_paper_events', 1);
});
