<?php

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\CandleGapRepairs;
use App\Domain\MarketData\ExchangeMetadata;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\RepairMissingCandles;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function gapRepairCandle(int $timestamp): array
{
    return ['microtimestamp' => $timestamp, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10'];
}

/** @param list<int> $timestamps */
function gapRepairFeed(string $period, array $timestamps): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', $period, array_map(gapRepairCandle(...), $timestamps));

    return $feed;
}

function gapRepairRepository(string $period = '1m'): ExchangeRepository
{
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->withArgs(fn ($exchange, $settings) => $exchange->class === 'kraken' && $settings['timeout'] === 15000);
    $repository->shouldReceive('prepareCandleMarket')->with('BTC/USD');
    $repository->shouldReceive('periods')->andReturn([$period => $period]);

    return $repository;
}

function runGapRepair(RepairMissingCandles $job, ExchangeRepository $repository, ExchangeMetadata $metadata): void
{
    $job->handle(
        app(CandleGapRepairs::class),
        $repository,
        app(TickerRepository::class),
        $metadata,
        app(BackfillIntelligence::class),
    );
}

beforeEach(function () {
    Cache::flush();
    config([
        'history_backfill.enabled' => true,
        'history_backfill.queue' => 'history',
        'history_backfill.page_size' => 90,
        'history_backfill.gap_empty_attempts_before_unavailable' => 5,
        'queue.default' => 'database',
    ]);
});

it('finds interior and trailing missing closed candles but ignores the open candle', function () {
    $this->travelTo('2026-10-01 10:05:30 UTC');
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    gapRepairFeed('1m', [$start, $start + 120000]);
    Bus::fake([RepairMissingCandles::class]);

    expect(app(CandleGapRepairs::class)->scan('kraken', 'BTC/USD', '1m', true))->toBe(1);

    $this->assertDatabaseHas('candle_gap_repairs', [
        'period' => '1m', 'from_ms' => $start + 60000, 'to_ms' => $start + 60000, 'status' => 'pending',
    ]);
    $this->assertDatabaseHas('candle_gap_repairs', [
        'period' => '1m', 'from_ms' => $start + 180000, 'to_ms' => $start + 240000, 'status' => 'pending',
    ]);
    $this->assertDatabaseMissing('candle_gap_repairs', ['from_ms' => $start + 300000]);

    expect(app(CandleGapRepairs::class)->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(2);
    Bus::assertDispatched(RepairMissingCandles::class, fn (RepairMissingCandles $job): bool => $job->queue === 'history');
});

it('repairs a missing candle and marks historical intelligence dirty', function () {
    $this->travelTo('2026-10-01 10:03:30 UTC');
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    $feed = gapRepairFeed('1m', [$start, $start + 120000]);
    Bus::fake([RepairMissingCandles::class, RebuildBackfilledIntelligence::class]);
    $repairs = app(CandleGapRepairs::class);
    $repairs->scan('kraken', 'BTC/USD', '1m', true);
    expect($repairs->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(1);
    $state = DB::table('candle_gap_repairs')->whereNotNull('lease_token')->first();

    $repository = gapRepairRepository();
    $repository->shouldReceive('fetchHistoryPage')->once()
        ->with('BTC/USD', '1m', $start + 60000, $start + 120000, 1)
        ->andReturn([gapRepairCandle($start + 60000)]);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);

    runGapRepair(new RepairMissingCandles($state->gap_id, $state->lease_token), $repository, $metadata);

    $this->assertDatabaseCount('tickers', 3);
    $this->assertDatabaseHas('candle_gap_repairs', [
        'gap_id' => $state->gap_id, 'status' => 'resolved', 'reason' => 'repaired', 'attempts' => 1,
    ]);
    $this->assertDatabaseHas('market_history_backfills', [
        'market_id' => $feed->market_id, 'period' => '1m', 'history_revision' => 1,
    ]);
});

it('marks a successfully queried but repeatedly absent candle unavailable', function () {
    $this->travelTo('2026-10-01 10:03:30 UTC');
    config(['history_backfill.gap_empty_attempts_before_unavailable' => 2]);
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    gapRepairFeed('1m', [$start, $start + 120000]);
    Bus::fake([RepairMissingCandles::class, RebuildBackfilledIntelligence::class]);
    $repairs = app(CandleGapRepairs::class);
    $repairs->scan('kraken', 'BTC/USD', '1m', true);

    $repository = gapRepairRepository();
    $repository->shouldReceive('fetchHistoryPage')->twice()->andReturn([]);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->twice()->andReturn([]);

    expect($repairs->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(1);
    $state = DB::table('candle_gap_repairs')->whereNotNull('lease_token')->first();
    runGapRepair(new RepairMissingCandles($state->gap_id, $state->lease_token), $repository, $metadata);
    $this->assertDatabaseHas('candle_gap_repairs', [
        'gap_id' => $state->gap_id, 'status' => 'retrying', 'empty_attempts' => 1, 'attempts' => 1,
    ]);

    $this->travel(61)->minutes();
    expect($repairs->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(1);
    $state = DB::table('candle_gap_repairs')->where('gap_id', $state->gap_id)->first();
    runGapRepair(new RepairMissingCandles($state->gap_id, $state->lease_token), $repository, $metadata);

    $this->assertDatabaseHas('candle_gap_repairs', [
        'gap_id' => $state->gap_id, 'status' => 'unavailable', 'reason' => 'exchange_omitted_candles',
        'empty_attempts' => 2, 'attempts' => 2,
    ]);
});

it('splits a partially repaired gap into the remaining missing ranges', function () {
    $this->travelTo('2026-10-01 10:05:30 UTC');
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    gapRepairFeed('1m', [$start, $start + 240000]);
    Bus::fake([RepairMissingCandles::class, RebuildBackfilledIntelligence::class]);
    $repairs = app(CandleGapRepairs::class);
    $repairs->scan('kraken', 'BTC/USD', '1m', true);
    expect($repairs->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(1);
    $state = DB::table('candle_gap_repairs')->whereNotNull('lease_token')->first();

    $repository = gapRepairRepository();
    $repository->shouldReceive('fetchHistoryPage')->once()
        ->with('BTC/USD', '1m', $start + 60000, $start + 240000, 3)
        ->andReturn([gapRepairCandle($start + 120000)]);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);

    runGapRepair(new RepairMissingCandles($state->gap_id, $state->lease_token), $repository, $metadata);

    $this->assertDatabaseHas('candle_gap_repairs', [
        'gap_id' => $state->gap_id, 'status' => 'resolved', 'reason' => 'partially_repaired',
    ]);
    $this->assertDatabaseHas('candle_gap_repairs', [
        'from_ms' => $start + 60000, 'to_ms' => $start + 60000, 'status' => 'pending',
    ]);
    $this->assertDatabaseHas('candle_gap_repairs', [
        'from_ms' => $start + 180000, 'to_ms' => $start + 180000, 'status' => 'pending',
    ]);
});

it('recovers an expired queued repair lease', function () {
    $this->travelTo('2026-10-01 10:03:30 UTC');
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    gapRepairFeed('1m', [$start, $start + 120000]);
    Bus::fake([RepairMissingCandles::class]);
    $repairs = app(CandleGapRepairs::class);
    $repairs->scan('kraken', 'BTC/USD', '1m', true);
    $gapId = DB::table('candle_gap_repairs')->value('gap_id');
    DB::table('candle_gap_repairs')->where('gap_id', $gapId)->update([
        'status' => 'queued', 'lease_token' => '00000000-0000-7000-8000-000000000001',
        'lease_until' => now()->subMinute(),
    ]);

    expect($repairs->dispatchDue('kraken', 'BTC/USD', '1m'))->toBe(1);
    $this->assertDatabaseHas('candle_gap_repairs', ['gap_id' => $gapId, 'status' => 'queued']);
    Bus::assertDispatchedTimes(RepairMissingCandles::class, 1);
});

it('runs automatic gap discovery from the scheduled backfill command', function () {
    $this->travelTo('2026-10-01 10:03:30 UTC');
    $start = strtotime('2026-10-01 10:00:00 UTC') * 1000;
    gapRepairFeed('1m', [$start, $start + 120000]);
    Bus::fake([RepairMissingCandles::class, BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    expect(Artisan::call('trademinator:backfill-ohlcv'))->toBe(0);

    expect(Artisan::output())->toContain('Scanned 1 market feeds and queued 1 missing-candle repairs on the history queue.');
    Bus::assertDispatchedTimes(RepairMissingCandles::class, 1);
});
