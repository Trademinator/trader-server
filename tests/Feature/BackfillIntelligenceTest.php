<?php

use App\Domain\Features\FeatureBuilder;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketHistoryBackfill;
use App\Jobs\BackfillMarketHistory;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\TrainMarketIntelligence;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function backfillTrainingCandle(int $timestamp): array
{
    $phase = $timestamp / 3600000;
    $open = 100 + 10 * sin($phase * M_PI / 10);
    $close = 100 + 10 * sin(($phase + 1) * M_PI / 10);

    return ['microtimestamp' => $timestamp, 'open' => (string) $open, 'close' => (string) $close,
        'high' => (string) (max($open, $close) + 0.2), 'low' => (string) (min($open, $close) - 0.2), 'volume' => '10'];
}

function backfillTrainingFeed(): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1h']);
    $start = strtotime('2024-01-02 UTC') * 1000;
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1h',
        array_map(backfillTrainingCandle(...), range($start, $start + 99 * 3600000, 3600000)));

    return $feed;
}

function importTrainingHistory(bool $empty = false): string
{
    expect(app(MarketHistoryBackfill::class)->dispatchDue())->toBe(1);
    $state = DB::table('market_history_backfills')->first();
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once()->with('BTC/USD');
    $repository->shouldReceive('periods')->once()->andReturn(['1h' => '1h']);
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturnUsing(
        fn ($symbol, $period, $from, $until) => $empty ? [] : array_map(backfillTrainingCandle(...), range($from, $until - 3600000, 3600000)));
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);
    (new BackfillMarketHistory($state->history_id, $state->lease_token))->handle(
        app(MarketHistoryBackfill::class), $repository, app(TickerRepository::class), $metadata, app(BackfillIntelligence::class));

    return $state->history_id;
}

function runBackfillTrainingStep(string $id): RebuildBackfilledIntelligence
{
    $state = DB::table('market_history_backfills')->where('history_id', $id)->first();
    expect($state->build_lease_token)->not->toBeNull();
    $job = new RebuildBackfilledIntelligence($id, $state->build_lease_token);
    $job->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    return $job;
}

beforeEach(function () {
    $path = sys_get_temp_dir().'/trademinator-backfill-'.Str::uuid7();
    config(['research.path' => $path.'/research', 'intelligence.path' => $path.'/models',
        'queue.default' => 'database', 'history_backfill.enabled' => true, 'intelligence.enabled' => true,
        'intelligence.schema' => 'core', 'intelligence.knn.train_size' => 36, 'intelligence.knn.test_size' => 12,
        'intelligence.knn.min_validation_rows' => 5, 'intelligence.knn.min_directional_predictions' => 1,
        'intelligence.patterns.enabled' => false, 'intelligence.horizon' => 2, 'intelligence.lookback' => 3]);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('coalesces successful imports into a feature rebuild followed by one fresh KNN model', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $this->travel(1)->minutes();
    importTrainingHistory();
    $this->assertDatabaseCount('market_features', 0);
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 2, 'trained_revision' => 0, 'build_stage' => 'features']);

    runBackfillTrainingStep($id);

    $this->assertDatabaseCount('market_features', 148);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', ['build_stage' => 'knn', 'build_revision' => 2]);
    $job = runBackfillTrainingStep($id);
    $job->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    $this->assertDatabaseCount('intelligence_models', 1);
    $this->assertDatabaseCount('research_datasets', 1);
    $this->assertDatabaseHas('intelligence_models', ['generation_key' => hash('sha256', 'history:'.$id.':2')]);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 2, 'build_stage' => null, 'build_error' => null]);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 2);
});

it('rebuilds after a successful backfill even when the weekly generation is already complete', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1h');
    (new TrainMarketIntelligence('kraken', 'BTC/USD', '1h', '2024-01-08'))->handle(app(MarketIntelligence::class));
    $weekly = app(ModelStore::class)->current('kraken', 'BTC/USD', '1h');
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);

    $current = app(ModelStore::class)->current('kraken', 'BTC/USD', '1h');
    expect($current['model_id'])->not->toBe($weekly['model_id']);
    expect($current['generation_key'])->toBe(hash('sha256', 'history:'.$id.':1'));
    $this->assertDatabaseCount('intelligence_models', 2);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 2);
});

it('keeps imports arriving after feature preparation pending until one follow-up rebuild completes', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    runBackfillTrainingStep($id);
    $this->travel(1)->minutes();
    importTrainingHistory();

    runBackfillTrainingStep($id);

    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 1, 'history_revision' => 2, 'build_stage' => 'features']);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 2, 'history_revision' => 2, 'build_stage' => null]);
    $this->assertDatabaseCount('intelligence_models', 2);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 4);
});

it('preserves imported candles and retries a failed feature rebuild before attempting KNN', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $lock = Cache::lock('trademinator:features:'.ModelStore::marketKey('kraken', 'BTC/USD', '1h'), 720);
    $lock->get();

    try {
        runBackfillTrainingStep($id);
    } finally {
        $lock->release();
    }

    $this->assertDatabaseCount('tickers', 124);
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 1, 'trained_revision' => 0,
        'build_stage' => 'features', 'build_failures' => 1,
        'build_error' => 'Features are already being built for this market and period.']);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(0);
    $this->travel(1)->minutes();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 1, 'build_failures' => 0, 'build_error' => null]);
    $this->assertDatabaseCount('intelligence_models', 1);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 3);
});

it('does not queue intelligence for an empty history window', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);

    importTrainingHistory(empty: true);

    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 0, 'build_stage' => null]);
    Bus::assertNotDispatched(RebuildBackfilledIntelligence::class);
});

it('retains pending training while intelligence is disabled and recovers stale build leases', function () {
    $this->travelTo('2024-01-10 00:00:00 UTC');
    backfillTrainingFeed();
    config(['intelligence.enabled' => false]);
    Bus::fake([BackfillMarketHistory::class, RebuildBackfilledIntelligence::class]);
    $id = importTrainingHistory();
    $this->assertDatabaseHas('market_history_backfills', ['history_revision' => 1, 'build_lease_token' => null]);
    config(['intelligence.enabled' => true]);
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);
    $first = DB::table('market_history_backfills')->first();
    $stale = new RebuildBackfilledIntelligence($id, $first->build_lease_token);
    $this->travel(16)->minutes();
    expect(app(BackfillIntelligence::class)->dispatchDue())->toBe(1);

    $stale->handle(app(BackfillIntelligence::class), app(FeatureBuilder::class), app(MarketIntelligence::class));

    $this->assertDatabaseCount('market_features', 0);
    runBackfillTrainingStep($id);
    runBackfillTrainingStep($id);
    $this->assertDatabaseHas('market_history_backfills', ['trained_revision' => 1, 'build_stage' => null]);
    $this->assertDatabaseCount('intelligence_models', 1);
    Bus::assertDispatchedTimes(RebuildBackfilledIntelligence::class, 3);
});
