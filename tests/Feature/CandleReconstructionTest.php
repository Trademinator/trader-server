<?php

use App\Domain\Archive\TickerArchive;
use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\CandleGapRepairs;
use App\Domain\MarketData\CandleReconstructor;
use App\Domain\MarketData\ExchangeMetadata;
use App\Jobs\RebuildBackfilledIntelligence;
use App\Jobs\RepairMissingCandles;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\bitso;
use ccxt\NetworkError;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function reconstructionBar(int $at, array $overrides = []): array
{
    return array_replace(['microtimestamp' => $at, 'open' => '11', 'high' => '12', 'low' => '10',
        'close' => '11.5', 'volume' => '0.000000000000000001'], $overrides);
}

function reconstructionFeed(int $at = 1704069000000, string $exchangeClass = 'bitso'): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => $exchangeClass, 'class' => $exchangeClass, 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'ATOM/USD', 'tick_size' => '0.001']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '15m']);
    app(TickerRepository::class)->saveTickers($exchangeClass, 'ATOM/USD', '15m', [reconstructionBar($at - 900000), reconstructionBar($at + 900000)]);

    return $feed->load('market.exchange');
}

function reconstructionLowerBars(int $at): array
{
    return [reconstructionBar($at), reconstructionBar($at + 300000, ['high' => '19', 'low' => '9', 'volume' => '0.000000000000000002']),
        reconstructionBar($at + 600000, ['close' => '14', 'high' => '15', 'low' => '5', 'volume' => '0.000000000000000003'])];
}

function reconstructionRepository(array $periods = ['5m', '1m', '15m', '30m', '1h']): ExchangeRepository
{
    $repository = Mockery::mock(ExchangeRepository::class);
    $repository->shouldReceive('periods')->andReturn(array_combine($periods, $periods));

    return $repository;
}

beforeEach(function () {
    config(['queue.default' => 'database', 'history_backfill.enabled' => true, 'intelligence.enabled' => true]);
});

it('reconstructs a missing candle from complete five minute evidence with exact decimal volume', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)
        ->andReturn(reconstructionLowerBars($at));

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar)->toMatchArray(['microtimestamp' => $at, 'open' => '11', 'high' => '19', 'low' => '5', 'close' => '14',
        'volume' => '0.000000000000000006']);
    expect($bar['reconstruction'])->toMatchArray(['method' => 'lower_timeframe', 'source_period' => '5m', 'available_at_ms' => $at + 900000]);
    $this->assertDatabaseCount('tickers', 2);
});

it('falls back to complete one minute candles when five minute candles are incomplete', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1m', $at, $at + 900000, 15)
        ->andReturn(array_map(fn ($i) => reconstructionBar($at + $i * 60000, ['volume' => '1']), range(0, 14)));

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar['reconstruction']['source_period'])->toBe('1m');
    expect($bar['volume'])->toBe('15.00');
});

it('leaves a hole unresolved when lower candles show activity but do not cover the full interval', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)->andReturn([reconstructionBar($at)]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1m', $at, $at + 900000, 15)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1h', $at - 1800000, $at + 1800000, 1)->andReturn([]);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
    $this->assertDatabaseCount('tickers', 2);
});

it('uses the previous close for an empty candle verified by parent counts volume prices and trade times', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '30m']);
    $sibling = reconstructionBar($at + 900000, ['open' => '50', 'high' => '55', 'low' => '49', 'close' => '52', 'volume' => '4',
        'trade_count' => 4, 'first_trade_time' => $at + 901000, 'last_trade_time' => $at + 902000]);
    $parent = array_replace($sibling, ['microtimestamp' => $at]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar)->toMatchArray(['open' => '11.5', 'high' => '11.5', 'low' => '11.5', 'close' => '11.5', 'volume' => '0']);
    expect($bar['reconstruction'])->toMatchArray(['method' => 'no_trades', 'available_at_ms' => $at + 1800000]);
});

it('rejects empty candle reconstruction when parent evidence conflicts or is missing', function (array $changes) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '30m']);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4', 'trade_count' => 4,
        'first_trade_time' => $at + 901000, 'last_trade_time' => $at + 902000]);
    $parent = array_replace($sibling, ['microtimestamp' => $at], $changes);
    $repository->shouldReceive('fetchCandleEvidence')->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
})->with([
    'volume residual below float precision' => [['volume' => '4.000000000000000001']],
    'trade count residual' => [['trade_count' => 5]],
    'missing trade count' => [['trade_count' => null]],
    'unexplained high' => [['high' => '100']],
    'unexplained first trade' => [['first_trade_time' => 1704069001000]],
]);

it('does not reconstruct consecutive gaps or a gap adjacent to another reconstruction', function (bool $reconstructedNeighbor) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    if ($reconstructedNeighbor) {
        Ticker::query()->where('microtimestamp', $at - 900000)->update(['payload' => json_encode(reconstructionBar($at - 900000,
            ['reconstruction' => ['method' => 'no_trades']]))]);
    } else {
        Ticker::query()->where('microtimestamp', $at + 900000)->delete();
    }
    $repository = Mockery::mock(ExchangeRepository::class);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
})->with([false, true]);

it('refuses unfinished parent evidence even when both neighboring candles are closed', function () {
    $this->travelTo('2024-01-01 01:30 UTC');
    $at = 1704070800000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '1h']);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
});

it('rejects duplicate or off-grid evidence rather than counting it as complete coverage', function (bool $duplicate) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $rows = reconstructionLowerBars($at);
    $rows[1]['microtimestamp'] = $duplicate ? $at : $at + 1;
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->andReturn($rows);

    expect(fn () => app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))
        ->toThrow(InvalidArgumentException::class, 'aligned');
})->with([false, true]);

it('preserves Bitso raw trade evidence without persisting or converting decimal prices to floats', function () {
    $at = 1704069000000;
    $client = Mockery::mock(bitso::class)->makePartial();
    $client->timeframes = ['5m' => '300'];
    $client->shouldReceive('market')->once()->with('ATOM/USD')->andReturn(['id' => 'atom_usd']);
    $client->shouldReceive('request')->once()->withArgs(fn ($path, $api, $method, $params) => $path === 'ohlc'
        && $params === ['book' => 'atom_usd', 'time_bucket' => '300', 'start' => $at, 'end' => $at + 900000])->andReturn([
            'success' => true, 'payload' => [['bucket_start_time' => $at, 'first_rate' => '11.123456789123456789',
                'max_rate' => '12', 'min_rate' => '10', 'last_rate' => '11.5', 'volume' => '0.000000000000000001',
                'trade_count' => 3, 'first_trade_time' => $at + 1, 'last_trade_time' => $at + 1000]],
        ]);
    $repository = new ExchangeRepository;
    $reflection = new ReflectionClass($repository);
    $reflection->getProperty('exchange')->setValue($repository, new Exchange(['class' => 'bitso']));
    $reflection->getProperty('ccxtExchange')->setValue($repository, $client);

    $rows = $repository->fetchCandleEvidence('ATOM/USD', '5m', $at, $at + 900000, 3);

    expect($rows[0])->toMatchArray(['open' => '11.123456789123456789', 'trade_count' => 3, 'first_trade_time' => $at + 1]);
    $this->assertDatabaseCount('tickers', 0);
});

it('repairs and records an isolated gap through the existing synchronous CLI', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $feed = reconstructionFeed($at);
    Bus::fake([RebuildBackfilledIntelligence::class]);
    app(CandleGapRepairs::class)->reconcileInterval($feed, $at, $at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('findByClass')->once()->andReturn(new Collection([$feed->market->exchange]));
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once();
    $repository->shouldReceive('fetch')->once()->with('ATOM/USD', '15m', intdiv($at - 900000, 1000), intdiv($at + 900000, 1000), 90)
        ->andReturn([reconstructionBar($at - 900000), reconstructionBar($at + 900000)]);
    $repository->shouldReceive('fetch')->once()->with('ATOM/USD', '15m', intdiv($at, 1000), intdiv($at + 900000 - 1, 1000), 90)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->andReturn(reconstructionLowerBars($at));
    app()->instance(ExchangeRepository::class, $repository);

    expect(Artisan::call('trademinator:sync-ohlcv', ['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--from' => '2024-01-01T00:15:00Z', '--to' => '2024-01-01T00:45:00Z', '--repair-gaps' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true);
    expect($output)->toMatchArray(['pages' => 1, 'fetched' => 2, 'repaired' => 1, 'reconstructed' => 1, 'missing_ranges' => 0]);
    expect($output['reconstruction_details'])->toBe([['candle_ms' => $at, 'reason' => 'reconstructed_lower_timeframe',
        'checks' => [['stage' => 'lower_timeframe', 'period' => '5m', 'rows' => 3, 'expected' => 3, 'reason' => 'complete']]]]);
    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at, 'status' => 'resolved', 'reason' => 'reconstructed_lower_timeframe', 'attempts' => 1]);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);
    Bus::assertDispatched(RebuildBackfilledIntelligence::class);
});

it('reconstructs through the queued repair and marks history for rebuilding exactly once', function (string $method) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $feed = reconstructionFeed($at);
    Bus::fake([RepairMissingCandles::class, RebuildBackfilledIntelligence::class]);
    $repairs = app(CandleGapRepairs::class);
    $repairs->reconcileInterval($feed, $at, $at);
    $repairs->dispatchDue('bitso', 'ATOM/USD', '15m');
    $state = DB::table('candle_gap_repairs')->first();
    $repository = reconstructionRepository();
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once();
    $repository->shouldReceive('fetchHistoryPage')->once()->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->times($method === 'next_open' ? 2 : 1)
        ->andReturn($method === 'next_open' ? [] : reconstructionLowerBars($at));
    $metadata = Mockery::mock(ExchangeMetadata::class);
    $metadata->shouldReceive('assertUsable')->once()->andReturn([]);

    (new RepairMissingCandles($state->gap_id, $state->lease_token))->handle($repairs, $repository, app(TickerRepository::class), $metadata, app(BackfillIntelligence::class));

    $this->assertDatabaseHas('candle_gap_repairs', ['gap_id' => $state->gap_id, 'status' => 'resolved', 'reason' => 'reconstructed_'.$method]);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);
    Bus::assertDispatched(RebuildBackfilledIntelligence::class);
})->with(['lower_timeframe', 'next_open']);

it('lets native candles replace reconstructions and never lets reconstructions overwrite native history', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $feed = reconstructionFeed($at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->andReturn(reconstructionLowerBars($at));
    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());
    $tickers = app(TickerRepository::class);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [$bar]);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [$bar]);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);

    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [reconstructionBar($at, ['close' => '10.5'])]);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [$bar]);

    $saved = iterator_to_array($tickers->streamHistory('bitso', 'ATOM/USD', '15m', $at, $at))[$at];
    expect($saved['close'])->toBe('10.5');
    expect($saved)->not->toHaveKey('reconstruction');
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 2]);
});

it('updates manual retry counts and eventually marks an unrecoverable gap unavailable', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    config(['history_backfill.gap_empty_attempts_before_unavailable' => 2]);
    $at = 1704069000000;
    $feed = reconstructionFeed($at);
    Bus::fake([RebuildBackfilledIntelligence::class]);
    app(CandleGapRepairs::class)->reconcileInterval($feed, $at, $at);
    $repository = reconstructionRepository(['15m']);
    $repository->shouldReceive('findByClass')->twice()->andReturn(new Collection([$feed->market->exchange]));
    $repository->shouldReceive('setExchange')->twice();
    $repository->shouldReceive('prepareCandleMarket')->twice();
    $repository->shouldReceive('fetch')->times(4)->andReturn([]);
    app()->instance(ExchangeRepository::class, $repository);
    $arguments = ['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--from' => '2024-01-01T00:15:00Z', '--to' => '2024-01-01T00:45:00Z', '--repair-gaps' => true];

    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);
    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at, 'status' => 'retrying', 'attempts' => 1, 'empty_attempts' => 1]);
    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);

    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at, 'status' => 'unavailable', 'attempts' => 2, 'empty_attempts' => 2]);
    $this->assertDatabaseCount('tickers', 2);
    Bus::assertNotDispatched(RebuildBackfilledIntelligence::class);
});

it('infers a next-open fill from empty responses but propagates provider errors', function (bool $error) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository();
    if ($error) {
        $repository->shouldReceive('fetchCandleEvidence')->once()->andThrow(new NetworkError('offline'));
        expect(fn () => app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))
            ->toThrow(NetworkError::class);
    } else {
        $repository->shouldReceive('fetchCandleEvidence')->times(2)->andReturn([]);
        $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());
        expect($bar)->toMatchArray(['open' => '11', 'high' => '11', 'low' => '11', 'close' => '11', 'volume' => '0']);
        expect($bar['reconstruction'])->toMatchArray(['method' => 'next_open', 'inferred' => true]);
    }
    $this->assertDatabaseCount('tickers', 2);
})->with([false, true]);

it('uses an hourly parent only when all other quarters reconcile exactly', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '30m', '1h']);
    $start = $at - 1800000;
    $siblings = array_map(fn ($offset) => reconstructionBar($start + $offset, ['volume' => '1', 'trade_count' => 2,
        'first_trade_time' => $start + $offset + 1, 'last_trade_time' => $start + $offset + 1000]), [0, 900000, 2700000]);
    $parent = array_replace($siblings[0], ['volume' => '3', 'trade_count' => 6, 'last_trade_time' => $siblings[2]['last_trade_time']]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1h', $start, $start + 3600000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $start, $start + 3600000, 4)->andReturn($siblings);

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar['reconstruction'])->toMatchArray(['method' => 'no_trades', 'source_period' => '1h']);
});

it('respects an existing feature lock before sending manual repair requests', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    reconstructionFeed();
    $lock = Cache::lock('trademinator:features:'.hash('sha256', 'bitso|ATOM/USD|15m'), 720);
    $lock->get();
    app()->instance(ExchangeRepository::class, Mockery::mock(ExchangeRepository::class));

    $status = Artisan::call('trademinator:sync-ohlcv', ['exchange' => 'bitso', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--from' => '2024-01-01T00:15:00Z', '--to' => '2024-01-01T00:45:00Z', '--repair-gaps' => true]);

    expect($status)->toBe(1);
    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    $lock->release();
});

it('lets a native candle supersede an archived reconstruction while preserving the archive evidence', function () {
    $this->travelTo('2024-02-02 UTC');
    $root = sys_get_temp_dir().'/trademinator-reconstruction-archive-'.Str::uuid7();
    config(['archive.enabled' => true, 'archive.root' => $root]);
    try {
        $at = 1704069000000;
        $feed = reconstructionFeed($at);
        $repository = reconstructionRepository();
        $repository->shouldReceive('fetchCandleEvidence')->once()->andReturn(reconstructionLowerBars($at));
        $bar = app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs());
        $tickers = app(TickerRepository::class);
        $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [$bar]);
        $archive = app(TickerArchive::class)->archiveMonth('bitso', 'ATOM/USD', '15m', 2024, 1);
        Ticker::query()->delete();
        $tickers->invalidateHistory('bitso', 'ATOM/USD', '15m');

        $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [reconstructionBar($at, ['close' => '10.5'])]);
        $rows = iterator_to_array($tickers->streamHistory('bitso', 'ATOM/USD', '15m', $at, $at));

        expect($rows[$at]['close'])->toBe('10.5');
        expect($rows[$at])->not->toHaveKey('reconstruction');
        $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 2]);
        expect(app(TickerArchive::class)->verifyManifest($archive['manifest']))->toBeArray();
    } finally {
        File::deleteDirectory($root);
    }
});

it('does not override contradictory smaller parent evidence with an apparently empty larger parent', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '30m', '1h']);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4', 'trade_count' => 4,
        'first_trade_time' => $at + 901000, 'last_trade_time' => $at + 902000]);
    $parent = array_replace($sibling, ['microtimestamp' => $at, 'trade_count' => 5, 'volume' => '5']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
});

it('rejects a parent trade inside the hole even without complete sibling evidence', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['15m', '30m', '1h']);
    $parent = reconstructionBar($at, ['volume' => '4', 'trade_count' => 4,
        'first_trade_time' => $at + 1000, 'last_trade_time' => $at + 902000]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();
});

it('replaces a partially repaired manual range with the remaining gap', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $feed = reconstructionFeed($at);
    Ticker::query()->where('microtimestamp', $at + 900000)->delete();
    $tickers = app(TickerRepository::class);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [reconstructionBar($at + 1800000)]);
    $before = iterator_to_array($tickers->streamHistory('bitso', 'ATOM/USD', '15m'));
    $repairs = app(CandleGapRepairs::class);
    $repairs->reconcileInterval($feed, $at, $at + 900000);
    $tickers->saveTickers('bitso', 'ATOM/USD', '15m', [reconstructionBar($at)]);
    $after = iterator_to_array($tickers->streamHistory('bitso', 'ATOM/USD', '15m'));

    $repairs->recordManualResult($feed, $at - 900000, $at + 1800000, $before, $after);

    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at, 'to_ms' => $at + 900000,
        'status' => 'resolved', 'reason' => 'partially_repaired']);
    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at + 900000, 'to_ms' => $at + 900000,
        'status' => 'retrying', 'empty_attempts' => 1]);
    $this->assertDatabaseCount('candle_gap_repairs', 2);
});

it('reconciles parent OHLCV and creates an empty candle for any exchange without requiring trade metadata', function (string $exchangeClass) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at, $exchangeClass);
    $repository = reconstructionRepository(['15m', '30m']);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4']);
    $parent = array_replace($sibling, ['microtimestamp' => $at]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    $attempt = app(CandleReconstructor::class)->attempt($repository, $exchangeClass, 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($attempt['candle'])->toMatchArray(['open' => '11.5', 'high' => '11.5', 'low' => '11.5', 'close' => '11.5', 'volume' => '0']);
    expect($attempt['candle']['reconstruction'])->toMatchArray(['method' => 'no_trades', 'verification' => 'parent_ohlcv',
        'available_at_ms' => $at + 1800000]);
    expect($attempt['diagnostics']['reason'])->toBe('reconstructed_no_trades');
})->with(['kraken', 'coinbase', 'ndax', 'cryptocom']);

it('recovers sparse smaller candles only when their activity and all siblings fully reconcile with the parent', function (bool $tradeMetadata) {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $exchangeClass = $tradeMetadata ? 'bitso' : 'kraken';
    reconstructionFeed($at, $exchangeClass);
    $repository = reconstructionRepository(['5m', '15m', '30m']);
    $lower = [reconstructionBar($at, ['volume' => '2']), reconstructionBar($at + 600000,
        ['high' => '19', 'low' => '9', 'close' => '15', 'volume' => '3'])];
    $sibling = reconstructionBar($at + 900000, ['open' => '20', 'high' => '21', 'low' => '19', 'close' => '20.5', 'volume' => '4']);
    $parent = reconstructionBar($at, ['high' => '21', 'low' => '9', 'close' => '20.5', 'volume' => '9']);
    if ($tradeMetadata) {
        foreach ($lower as &$row) {
            $row += ['trade_count' => (int) $row['volume'], 'first_trade_time' => $row['microtimestamp'] + 1,
                'last_trade_time' => $row['microtimestamp'] + 1000];
        }
        unset($row);
        $sibling += ['trade_count' => 4, 'first_trade_time' => $at + 900001, 'last_trade_time' => $at + 901000];
        $parent += ['trade_count' => 9, 'first_trade_time' => $at + 1, 'last_trade_time' => $at + 901000];
    }
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)->andReturn($lower);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    $bar = app(CandleReconstructor::class)->reconstruct($repository, $exchangeClass, 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar)->toMatchArray(['open' => '11', 'high' => '19', 'low' => '9', 'close' => '15', 'volume' => '5.00']);
    expect($bar['reconstruction'])->toMatchArray(['method' => 'lower_timeframe', 'source_period' => '5m',
        'available_at_ms' => $at + 1800000]);
    expect($bar['reconstruction']['evidence']['lower'])->toHaveCount(2);
    expect($bar['reconstruction']['verification'])->toBe($tradeMetadata ? 'parent_ohlcv_and_trade_counts' : 'parent_ohlcv');
})->with(['OHLCV evidence' => false, 'OHLCV with trade counts and times' => true]);

it('reports a tiny unexplained volume residual instead of rounding it into an empty interval', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at, 'kraken');
    $repository = reconstructionRepository(['15m', '30m']);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4']);
    $parent = array_replace($sibling, ['microtimestamp' => $at, 'volume' => '4.000000000000000001']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);

    $attempt = app(CandleReconstructor::class)->attempt($repository, 'kraken', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($attempt['candle'])->toBeNull();
    expect($attempt['diagnostics']['reason'])->toBe('parent_volume_mismatch');
    $this->assertDatabaseCount('tickers', 2);
});

it('uses supported three minute candles when five and one minute candles are unavailable', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at, 'kraken');
    $repository = reconstructionRepository(['3m', '15m']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '3m', $at, $at + 900000, 5)
        ->andReturn(array_map(fn ($i) => reconstructionBar($at + $i * 180000, ['volume' => '1']), range(0, 4)));

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'kraken', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar['volume'])->toBe('5.00');
    expect($bar['reconstruction']['source_period'])->toBe('3m');
});

it('chooses an available two hour parent when thirty minute and hourly parents are unsupported', function () {
    $this->travelTo('2024-01-01 03:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at, 'kraken');
    $repository = reconstructionRepository(['15m', '2h']);
    $start = $at - 1800000;
    $siblings = array_map(fn ($i) => reconstructionBar($start + $i * 900000, ['volume' => '1']), [0, 1, 3, 4, 5, 6, 7]);
    $parent = reconstructionBar($start, ['volume' => '7']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '2h', $start, $start + 7200000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $start, $start + 7200000, 8)->andReturn($siblings);

    $bar = app(CandleReconstructor::class)->reconstruct($repository, 'kraken', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($bar['reconstruction'])->toMatchArray(['method' => 'no_trades', 'source_period' => '2h', 'available_at_ms' => $start + 7200000]);
});

it('repairs an empty interval and publishes diagnostics through the generic synchronous command', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    $feed = reconstructionFeed($at, 'kraken');
    Bus::fake([RebuildBackfilledIntelligence::class]);
    $repository = reconstructionRepository(['15m', '30m']);
    $repository->shouldReceive('findByClass')->once()->andReturn(new Collection([$feed->market->exchange]));
    $repository->shouldReceive('setExchange')->once();
    $repository->shouldReceive('prepareCandleMarket')->once();
    $repository->shouldReceive('fetch')->twice()->andReturn([]);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4']);
    $parent = array_replace($sibling, ['microtimestamp' => $at]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$sibling]);
    app()->instance(ExchangeRepository::class, $repository);

    expect(Artisan::call('trademinator:sync-ohlcv', ['exchange' => 'kraken', 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--from' => '2024-01-01T00:15:00Z', '--to' => '2024-01-01T00:45:00Z', '--repair-gaps' => true]))->toBe(0);

    $output = json_decode(Artisan::output(), true);
    expect($output)->toMatchArray(['repaired' => 1, 'reconstructed' => 1, 'missing_ranges' => 0, 'reconstruction_details_omitted' => 0]);
    expect($output['reconstruction_details'][0]['reason'])->toBe('reconstructed_no_trades');
    $saved = iterator_to_array(app(TickerRepository::class)->streamHistory('kraken', 'ATOM/USD', '15m', $at, $at))[$at];
    expect($saved['reconstruction']['verification'])->toBe('parent_ohlcv');
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);
    Bus::assertDispatched(RebuildBackfilledIntelligence::class);
});

it('uses a native candle found during parent verification without labelling it reconstructed', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at, 'kraken');
    $repository = reconstructionRepository(['15m', '30m']);
    $native = reconstructionBar($at, ['volume' => '4']);
    $sibling = reconstructionBar($at + 900000, ['volume' => '4']);
    $parent = reconstructionBar($at, ['volume' => '8']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '30m', $at, $at + 1800000, 1)->andReturn([$parent]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '15m', $at, $at + 1800000, 2)->andReturn([$native, $sibling]);

    $attempt = app(CandleReconstructor::class)->attempt($repository, 'kraken', 'ATOM/USD', '15m', $at, now()->getTimestampMs());

    expect($attempt['candle'])->toMatchArray($native)->not->toHaveKey('reconstruction');
    expect($attempt['diagnostics']['reason'])->toBe('native_candle_recovered');
});

it('fills the reported gap from the next open after empty lower requests without requesting parent metadata', function (string $exchangeClass) {
    $this->travelTo('2024-02-29 00:00 UTC');
    $at = 1709154000000;
    $feed = reconstructionFeed($at, $exchangeClass);
    Bus::fake([RebuildBackfilledIntelligence::class]);
    $previous = reconstructionBar($at - 900000, ['open' => '11.128', 'high' => '11.200', 'low' => '11.116', 'close' => '11.193']);
    $next = reconstructionBar($at + 900000, ['open' => '11.167', 'high' => '11.211', 'low' => '11.167', 'close' => '11.211']);
    $tickers = app(TickerRepository::class);
    $tickers->saveTickers($exchangeClass, 'ATOM/USD', '15m', [$previous, $next]);
    app(CandleGapRepairs::class)->reconcileInterval($feed, $at, $at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('findByClass')->twice()->andReturn(new Collection([$feed->market->exchange]));
    $repository->shouldReceive('setExchange')->twice();
    $repository->shouldReceive('prepareCandleMarket')->twice();
    $repository->shouldReceive('fetch')->times(3)->andReturn([$previous, $next], [], [$previous, $next]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1m', $at, $at + 900000, 15)->andReturn([]);
    app()->instance(ExchangeRepository::class, $repository);
    $arguments = ['exchange' => $exchangeClass, 'symbol' => 'ATOM/USD', 'period' => '15m',
        '--from' => '2024-02-28T20:45:00Z', '--to' => '2024-02-28T21:15:00Z', '--repair-gaps' => true];

    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);
    $output = json_decode(Artisan::output(), true);
    $saved = iterator_to_array($tickers->streamHistory($exchangeClass, 'ATOM/USD', '15m', $at, $at))[$at];

    expect($output)->toMatchArray(['fetched' => 2, 'repaired' => 1, 'reconstructed' => 1, 'missing_ranges' => 0]);
    expect($output['reconstruction_details'][0]['reason'])->toBe('reconstructed_next_open');
    expect($saved)->toMatchArray(['open' => '11.167', 'high' => '11.167', 'low' => '11.167', 'close' => '11.167', 'volume' => '0']);
    expect($saved['reconstruction'])->toMatchArray(['method' => 'next_open', 'inferred' => true,
        'verification' => 'empty_lower_timeframes', 'available_at_ms' => 1709155800000]);
    $this->assertDatabaseHas('candle_gap_repairs', ['from_ms' => $at, 'status' => 'resolved', 'reason' => 'reconstructed_next_open']);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);
    Bus::assertDispatched(RebuildBackfilledIntelligence::class);

    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);
    expect(json_decode(Artisan::output(), true))->toMatchArray(['repaired' => 0, 'reconstructed' => 0, 'missing_ranges' => 0]);
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 1]);

    $tickers->saveTickers($exchangeClass, 'ATOM/USD', '15m', [reconstructionBar($at)]);
    $native = iterator_to_array($tickers->streamHistory($exchangeClass, 'ATOM/USD', '15m', $at, $at))[$at];
    expect($native)->not->toHaveKey('reconstruction');
    $this->assertDatabaseHas('market_history_backfills', ['market_id' => $feed->market_id, 'history_revision' => 2]);
})->with(['bitso', 'kraken']);

it('does not infer a fill when a later lower timeframe request fails after an empty response', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository();
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)->andReturn([]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1m', $at, $at + 900000, 15)->andThrow(new NetworkError('offline'));

    expect(fn () => app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))
        ->toThrow(NetworkError::class);

    $this->assertDatabaseCount('tickers', 2);
});

it('does not treat a partial lower series with zero volume as a zero-row response', function () {
    $this->travelTo('2024-01-01 02:00 UTC');
    $at = 1704069000000;
    reconstructionFeed($at);
    $repository = reconstructionRepository(['5m', '1m', '15m']);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '5m', $at, $at + 900000, 3)
        ->andReturn([reconstructionBar($at, ['open' => '11', 'high' => '11', 'low' => '11', 'close' => '11', 'volume' => '0'])]);
    $repository->shouldReceive('fetchCandleEvidence')->once()->with('ATOM/USD', '1m', $at, $at + 900000, 15)->andReturn([]);

    expect(app(CandleReconstructor::class)->reconstruct($repository, 'bitso', 'ATOM/USD', '15m', $at, now()->getTimestampMs()))->toBeNull();

    $this->assertDatabaseCount('tickers', 2);
});
