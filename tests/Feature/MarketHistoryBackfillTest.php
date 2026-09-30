<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketHistoryBackfill;
use App\Domain\Research\FeatureSchema;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\BadRequest;
use ccxt\RateLimitExceeded;
use ccxt\RequestTimeout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function historyCandle(int $timestamp): array
{
    return ['microtimestamp' => $timestamp, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10'];
}

function historyFeed(string $period = '1h', ?int $oldest = null): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
    if ($oldest !== null) {
        app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', $period, [historyCandle($oldest)]);
    }

    return $feed;
}

function claimHistory(): BackfillMarketHistory
{
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(1);
    $state = DB::table('market_history_backfills')->whereNotNull('lease_token')->first();

    return new BackfillMarketHistory($state->history_id, $state->lease_token);
}

function runHistory(BackfillMarketHistory $job, ExchangeRepository $repository, ?TickerRepository $tickers = null): void
{
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->andReturn([]);
    $job->handle(app(MarketHistoryBackfill::class), $repository, $tickers ?? app(TickerRepository::class), $metadata, app(BackfillIntelligence::class));
}

function historyRepository(string $period = '1h'): ExchangeRepository
{
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->withArgs(fn ($exchange, $settings) => $exchange->class === 'kraken' && $settings['timeout'] === 15000);
    $repository->shouldReceive('prepareCandleMarket')->with('BTC/USD');
    $repository->shouldReceive('periods')->andReturn([$period => $period]);

    return $repository;
}

beforeEach(function () {
    config(['history_backfill.enabled' => true, 'queue.default' => 'database']);
});

it('claims one shared history for two subscribers and rejects stale jobs after lease recovery', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $feed = historyFeed();
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $feed->market_id, 'active' => true]);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    $stale = claimHistory();
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(0);
    $this->travel(16)->minutes();
    $current = claimHistory();
    runHistory($stale, Mockery::mock(ExchangeRepository::class));

    $this->assertDatabaseCount('market_history_backfills', 1);
    $this->assertDatabaseHas('market_history_backfills', ['lease_token' => $current->leaseToken, 'status' => 'queued']);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 2);
});

it('resumes a full day of minute candles across bounded jobs and makes them usable by M2', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $oldest = strtotime('2026-09-28 00:00:00 UTC') * 1000;
    $start = strtotime('2026-09-27 00:00:00 UTC') * 1000;
    historyFeed('1m', $oldest);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository('1m');
    $repository->shouldReceive('fetchHistoryPage')->times(16)->andReturnUsing(
        function ($symbol, $period, $from, $until, $size): array {
            expect($size)->toBe(90);
            expect($until - $from)->toBeLessThanOrEqual(90 * 60000);

            return array_map(historyCandle(...), range($from, $until - 60000, 60000));
        });

    for ($i = 0; $i < 4; $i++) {
        runHistory(claimHistory(), $repository);
        $this->travel(1)->minutes();
    }
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');

    $this->assertDatabaseCount('tickers', 1441);
    $this->assertDatabaseCount('market_features', 1441);
    $this->assertDatabaseHas('market_history_backfills', [
        'before_ms' => $start, 'oldest_candle_ms' => $start, 'candles_received' => 1440,
        'window_start_ms' => null, 'status' => 'active',
    ]);
    $historical = MarketFeature::query()->where('microtimestamp', $oldest - 60000)->firstOrFail();
    expect(FeatureSchema::vector($historical->payload, FeatureSchema::keys('core')))->not->toBeNull();
    expect($historical->payload['context_ready'])->toBeFalse();
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 4);
});

it('uses at least one complete candle for periods longer than one day', function (string $period, string $oldest, string $start) {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $oldest = strtotime($oldest.' UTC') * 1000;
    $start = strtotime($start.' UTC') * 1000;
    historyFeed($period, $oldest);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository($period);
    $repository->shouldReceive('fetchHistoryPage')->once()->with('BTC/USD', $period, $start, $oldest, 90)
        ->andReturn([historyCandle($start)]);

    runHistory(claimHistory(), $repository);

    $this->assertDatabaseHas('market_history_backfills', ['before_ms' => $start, 'candles_received' => 1]);
    $this->assertDatabaseCount('tickers', 2);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 1);
})->with([
    'three days' => ['3d', '2026-03-15', '2026-03-12'],
    'calendar month' => ['1M', '2026-03-01', '2026-02-01'],
    'calendar year' => ['1y', '2025-01-01', '2024-01-01'],
]);

it('keeps a short-page checkpoint on timeout and retries it without duplicates or skipped candles', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $end = strtotime('2026-09-28 UTC') * 1000;
    $start = $end - 86400000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->once()->with('BTC/USD', '1h', $start, $end, 90)
        ->andReturn([historyCandle($start + 3600000), historyCandle($start)]);
    $repository->shouldReceive('fetchHistoryPage')->once()->with('BTC/USD', '1h', $start + 7200000, $end, 90)
        ->andThrow(new RequestTimeout('Temporary timeout'));

    runHistory(claimHistory(), $repository);

    $this->assertDatabaseHas('market_history_backfills', [
        'status' => 'retrying', 'before_ms' => $end, 'next_since_ms' => $start + 7200000,
        'candles_received' => 2, 'failures' => 1, 'empty_windows' => 0,
    ]);
    $this->travel(1)->minutes();
    $retry = historyRepository();
    $retry->shouldReceive('fetchHistoryPage')->once()->with('BTC/USD', '1h', $start + 7200000, $end, 90)
        ->andReturn(array_map(historyCandle(...), range($start + 7200000, $end - 3600000, 3600000)));
    runHistory(claimHistory(), $retry);

    $this->assertDatabaseCount('tickers', 25);
    $this->assertDatabaseHas('market_history_backfills', ['status' => 'active', 'before_ms' => $start, 'candles_received' => 24, 'failures' => 0]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 2);
});

it('pauses after three empty or clamped windows and can explicitly continue further back', function (bool $clamped) {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $end = strtotime('2026-09-28 UTC') * 1000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->times(3)->andReturn($clamped ? [historyCandle($end)] : []);

    for ($i = 0; $i < 3; $i++) {
        runHistory(claimHistory(), $repository);
        $this->travel(1)->minutes();
    }

    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(0);
    $this->assertDatabaseHas('market_history_backfills', [
        'status' => 'paused', 'reason' => 'no_older_data', 'before_ms' => $end - 3 * 86400000,
        'oldest_candle_ms' => $end, 'candles_received' => 0, 'empty_windows' => 3,
    ]);
    $this->artisan('trademinator:backfill-ohlcv', [
        '--exchange' => 'kraken', '--symbol' => 'BTC/USD', '--period' => '1h', '--resume' => true,
    ])->assertSuccessful();
    $this->assertDatabaseHas('market_history_backfills', ['status' => 'queued', 'before_ms' => $end - 3 * 86400000, 'empty_windows' => 0]);
    $this->assertDatabaseCount('tickers', 1);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 4);
})->with(['empty' => false, 'ignores since' => true]);

it('continues through quiet days and clears the no-progress counter when older trades exist', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $end = strtotime('2026-09-28 UTC') * 1000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->times(3)->andReturn([], [], [historyCandle($end - 2 * 86400000 - 3600000)]);

    for ($i = 0; $i < 3; $i++) {
        runHistory(claimHistory(), $repository);
        $this->travel(1)->minutes();
    }

    $this->assertDatabaseHas('market_history_backfills', ['status' => 'active', 'empty_windows' => 0, 'candles_received' => 1]);
    $this->assertDatabaseCount('tickers', 2);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 3);
});

it('backs off transient errors and pauses repeated request errors without declaring history exhausted', function (string $exception, string $status) {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    config(['history_backfill.errors_before_pause' => 2]);
    $end = strtotime('2026-09-28 UTC') * 1000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->twice()->andThrow(new $exception('Test exchange failure'));

    runHistory(claimHistory(), $repository);
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(0);
    $this->travel(1)->minutes();
    runHistory(claimHistory(), $repository);

    $this->assertDatabaseHas('market_history_backfills', [
        'status' => $status, 'before_ms' => $end, 'next_since_ms' => $end - 86400000,
        'failures' => 2, 'empty_windows' => 0, 'candles_received' => 0,
    ]);
    $this->assertDatabaseCount('tickers', 1);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 2);
})->with([
    'timeout is not a history boundary' => [RequestTimeout::class, 'retrying'],
    'rate limit is not a history boundary' => [RateLimitExceeded::class, 'retrying'],
    'repeated bad request needs review' => [BadRequest::class, 'paused'],
]);

it('rolls back candle inserts if a page fails before its checkpoint is committed', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay());
    $end = strtotime('2026-09-28 UTC') * 1000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturn([historyCandle($end - 3600000)]);
    $tickers = Mockery::mock(TickerRepository::class);
    $tickers->shouldReceive('saveTickers')->once()->andReturnUsing(function (...$args): int {
        (new TickerRepository)->saveTickers(...$args);
        throw new RuntimeException('Interrupted page transaction');
    });

    runHistory(claimHistory(), $repository, $tickers);

    $this->assertDatabaseCount('tickers', 1);
    $this->assertDatabaseHas('market_history_backfills', ['status' => 'retrying', 'next_since_ms' => $end - 86400000, 'candles_received' => 0]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 1);
});

it('rejects out-of-window and unfinished candles from an exchange response', function () {
    $this->travelTo(now()->setDate(2026, 9, 29)->startOfDay()->addMinutes(30));
    $end = strtotime('2026-09-28 UTC') * 1000;
    historyFeed('1h', $end);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $repository = historyRepository();
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturn([
        historyCandle($end - 90000000), historyCandle($end - 3600000), historyCandle($end),
        historyCandle(strtotime('2026-09-29 UTC') * 1000), historyCandle(now()->addDay()->getTimestampMs()),
    ]);

    runHistory(claimHistory(), $repository);

    $this->assertDatabaseCount('tickers', 2);
    $this->assertDatabaseHas('market_history_backfills', ['candles_received' => 1, 'oldest_candle_ms' => $end - 3600000]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 1);
});

it('defers to a collector or feature build without releasing its database claim', function (string $kind) {
    $feed = historyFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $job = claimHistory();
    $key = match ($kind) {
        'market' => 'trademinator:market-feed:'.$feed->market_id,
        'features' => 'trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1h'),
        'exchange' => 'trademinator:history-exchange:kraken',
    };
    $lock = Cache::lock($key, 180);
    $lock->get();
    $job->withFakeQueueInteractions();

    try {
        runHistory($job, Mockery::mock(ExchangeRepository::class));
    } finally {
        $lock->release();
    }

    $job->assertReleased(30);
    $this->assertDatabaseHas('market_history_backfills', ['status' => 'queued', 'lease_token' => $job->leaseToken]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 1);
})->with(['market', 'features', 'exchange']);

it('does not collect after the last unsubscribe or reuse a cursor for a changed period', function () {
    $feed = historyFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $oldJob = claimHistory();
    $feed->update(['selected_period' => '1M']);
    runHistory($oldJob, Mockery::mock(ExchangeRepository::class));
    $newJob = claimHistory();
    MarketSubscription::query()->where('market_id', $feed->market_id)->update(['active' => false]);
    runHistory($newJob, Mockery::mock(ExchangeRepository::class));

    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(0);
    $this->assertDatabaseHas('market_history_backfills', ['period' => '1h', 'status' => 'idle']);
    $this->assertDatabaseHas('market_history_backfills', ['period' => '1M', 'status' => 'idle', 'before_ms' => null]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 2);
});

it('keeps status read-only and rejects unsafe resume options and inline queue dispatch', function () {
    historyFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    config(['queue.default' => 'sync']);

    $this->artisan('trademinator:backfill-ohlcv')->assertFailed();
    $this->artisan('trademinator:backfill-ohlcv', ['--resume' => true])->assertFailed();
    $this->artisan('trademinator:backfill-ohlcv', ['--period' => 'bogus'])->assertFailed();
    expect(Artisan::call('trademinator:backfill-ohlcv', ['--status' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true))->toBe([]);
    config(['history_backfill.enabled' => false]);
    $this->artisan('trademinator:backfill-ohlcv')->assertSuccessful();

    $this->assertDatabaseCount('market_history_backfills', 0);
    Bus::assertNotDispatched(BackfillMarketHistory::class);
});

it('pauses before any network call when the exchange access review blocks the adapter', function () {
    historyFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $job = claimHistory();
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andThrow(
        new MarketCatalogException('access_unknown', 'Adapter needs review.', 422));

    $job->handle(app(MarketHistoryBackfill::class), Mockery::mock(ExchangeRepository::class),
        app(TickerRepository::class), $metadata, app(BackfillIntelligence::class));

    $this->assertDatabaseHas('market_history_backfills', [
        'status' => 'paused', 'reason' => 'access_or_support', 'last_error' => 'Adapter needs review.',
        'before_ms' => null, 'failures' => 1, 'empty_windows' => 0,
    ]);
    Bus::assertDispatchedTimes(BackfillMarketHistory::class, 1);
});
