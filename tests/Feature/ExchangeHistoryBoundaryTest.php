<?php

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketHistoryBackfill;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

function boundaryCandle(int $timestamp): array
{
    return ['microtimestamp' => $timestamp, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '10'];
}

function boundaryFeed(int $oldest): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'NDAX', 'class' => 'ndax', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1h',
        'status' => 'ready', 'next_pull_at' => now()->addHour()]);
    app(TickerRepository::class)->saveTickers('ndax', 'BTC/USD', '1h', [boundaryCandle($oldest)]);

    return $feed;
}

function boundaryClaim(): BackfillMarketHistory
{
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(1);
    $state = DB::table('market_history_backfills')->whereNotNull('lease_token')->first();

    return new BackfillMarketHistory($state->history_id, $state->lease_token);
}

it('confirms an exchange retention boundary with recent data and requests a safer period reselection', function () {
    $this->travelTo('2026-09-29 00:00:00 UTC');
    config(['queue.default' => 'database', 'history_backfill.enabled' => true,
        'history_backfill.empty_windows_before_pause' => 1, 'history_backfill.minimum_days' => 7,
        'history_backfill.depth_probe_candles' => 3, 'history_backfill.reselect_shallow_periods' => true]);
    $oldest = strtotime('2026-09-28 00:00:00 UTC') * 1000;
    $feed = boundaryFeed($oldest);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('periods')->once()->andReturn(['1h' => '1h']);
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturn([]);
    $repository->shouldReceive('hasHistoricalData')->once()->andReturn(true);
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);

    boundaryClaim()->handle(app(MarketHistoryBackfill::class), $repository, app(TickerRepository::class),
        $metadata, app(BackfillIntelligence::class));

    $this->assertDatabaseHas('market_history_backfills', [
        'market_id' => $feed->market_id, 'period' => '1h', 'status' => 'paused',
        'reason' => 'exchange_history_boundary', 'oldest_candle_ms' => $oldest,
    ]);
    $feed->refresh();
    expect($feed->selected_period)->toBeNull()
        ->and($feed->status)->toBe('pending')
        ->and($feed->next_pull_at)->not->toBeNull()
        ->and($feed->last_error)->toContain('insufficient retrievable history');
});
