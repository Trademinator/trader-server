<?php

use App\Domain\Archive\FeatureCheckpointStore;
use App\Domain\Features\FeatureBuilder;
use App\Domain\Features\FeatureBuildLocked;
use App\Domain\Features\FeatureEngine;
use App\Domain\Features\FeatureReplayTimeout;
use App\Domain\Operations\ActionLog;
use App\Jobs\BuildMarketFeatures;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function featureQueueCandles(int $count): array
{
    $candles = [];
    for ($i = 0; $i < $count; $i++) {
        $close = 100 + $i % 7;
        $candles[] = [
            'microtimestamp' => 1_700_000_000_000 + $i * 60_000,
            'open' => (string) $close, 'high' => (string) ($close + 2),
            'low' => (string) ($close - 2), 'close' => (string) $close, 'volume' => '10',
        ];
    }

    return $candles;
}

function drainFeatureQueue(string $queue = 'features'): int
{
    $count = 0;
    while ($count < 20 && ($job = Queue::connection('database')->pop($queue)) !== null) {
        expect($job->attempts())->toBe(1);
        $job->fire();
        $count++;
    }

    return $count;
}

it('queues feature work separately and only while the current feature version is behind', function () {
    config(['features.enabled' => true, 'features.queue' => 'features', 'archive.enabled' => false]);
    Queue::fake([BuildMarketFeatures::class]);

    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'ready']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id,
        'active' => true,
    ]);

    $timestamp = 1_700_000_000_000;
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', [[
        'microtimestamp' => $timestamp,
        'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '10',
    ]]);

    $this->artisan('trademinator:dispatch-market-features')
        ->expectsOutput('Dispatched 1 shared-market feature builds.')
        ->assertSuccessful();

    Queue::assertPushed(BuildMarketFeatures::class, function (BuildMarketFeatures $job): bool {
        return $job->exchange === 'kraken'
            && $job->symbol === 'BTC/USD'
            && $job->period === '1m'
            && $job->queue === 'features';
    });

    DB::table('market_features')->insert([
        'feature_id' => (string) Str::uuid7(),
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        'microtimestamp' => $timestamp,
        'available_at_ms' => $timestamp + 60_000,
        'version' => FeatureEngine::VERSION,
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Queue::fake([BuildMarketFeatures::class]);
    $this->artisan('trademinator:dispatch-market-features')
        ->expectsOutput('Dispatched 0 shared-market feature builds.')
        ->assertSuccessful();
    Queue::assertNothingPushed();
});

it('finishes more than three chunks from an older queued payload without changing feature values', function () {
    config(['features.queue_chunk_candles' => 40, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(211));
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    $expected = DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all();
    DB::table('market_features')->delete();
    app(FeatureCheckpointStore::class)->clear('kraken', 'BTC/USD', '1m');
    $job = (new BuildMarketFeatures('kraken', 'BTC/USD', '1m'))->onConnection('database')->onQueue('feature-replay');
    unset($job->replayFromMs, $job->replayCutoffMs, $job->checkpointBeforeMs, $job->chunkCandles);

    dispatch($job);
    $jobs = drainFeatureQueue('feature-replay');

    expect($jobs)->toBe(6);
    expect(DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all())->toBe($expected);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

it('continues checkpoint warmup even when early chunks produce no new feature rows', function () {
    config(['features.queue_chunk_candles' => 40, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(230));
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    $expected = DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all();
    DB::table('market_features')->where('microtimestamp', '>=', 1_700_009_600_000)->delete();
    app(FeatureCheckpointStore::class)->clear('kraken', 'BTC/USD', '1m');

    BuildMarketFeatures::dispatch('kraken', 'BTC/USD', '1m')->onConnection('database');
    $jobs = drainFeatureQueue();

    expect($jobs)->toBe(6);
    expect(DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all())->toBe($expected);
    $this->assertDatabaseCount('jobs', 0);
});

it('resumes a timed out replay from its safe checkpoint and logs the continuation', function () {
    config(['features.queue_chunk_candles' => 1000, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(1200));
    app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m');
    $expected = DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all();
    DB::table('market_features')->delete();
    app(FeatureCheckpointStore::class)->clear('kraken', 'BTC/USD', '1m');
    $log = Mockery::spy(ActionLog::class);
    $this->app->instance(ActionLog::class, $log);
    $timedOut = false;
    DB::listen(function (QueryExecuted $query) use (&$timedOut): void {
        if (! $timedOut && str_starts_with($query->sql, 'insert into "market_features"')
            && DB::table('market_features')->count() === 700) {
            $timedOut = true;
            throw new FeatureReplayTimeout(1_700_029_940_000, 700);
        }
    });

    BuildMarketFeatures::dispatch('kraken', 'BTC/USD', '1m')->onConnection('database');
    $jobs = drainFeatureQueue();

    expect($timedOut)->toBeTrue();
    expect($jobs)->toBe(3);
    expect(DB::table('market_features')->orderBy('microtimestamp')->pluck('payload')->all())->toBe($expected);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    $log->shouldHaveReceived('write')->with('features.continued', Mockery::on(fn (array $fields): bool => $fields['reason'] === 'time_budget' && $fields['rows'] === 700
        && $fields['candle_ms'] === 1_700_029_940_000 && $fields['queue'] === 'features'))->once();
});

it('shrinks a chunk when the time budget expires before the first checkpoint', function () {
    config(['features.queue_chunk_candles' => 8, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(10));
    $timedOut = false;
    DB::listen(function (QueryExecuted $query) use (&$timedOut): void {
        if (! $timedOut && str_contains($query->sql, '"coin_gecko_market_mappings"')) {
            $timedOut = true;
            throw new FeatureReplayTimeout;
        }
    });

    BuildMarketFeatures::dispatch('kraken', 'BTC/USD', '1m')->onConnection('database');
    $jobs = drainFeatureQueue();

    expect($timedOut)->toBeTrue();
    expect($jobs)->toBe(4);
    $this->assertDatabaseCount('market_features', 10);
    $this->assertDatabaseCount('jobs', 0);
});

it('does not endlessly replace a replay that cannot process even one candle', function () {
    config(['features.queue_chunk_candles' => 2, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(3));
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, '"coin_gecko_market_mappings"')) {
            throw new FeatureReplayTimeout;
        }
    });
    BuildMarketFeatures::dispatch('kraken', 'BTC/USD', '1m')->onConnection('database');
    Queue::connection('database')->pop('features')->fire();
    $last = Queue::connection('database')->pop('features');

    expect(fn () => $last->fire())->toThrow(FeatureReplayTimeout::class);

    $this->assertDatabaseCount('market_features', 0);
    $this->assertDatabaseCount('jobs', 1);
});

it('releases feature work instead of failing when the market feature lock is busy', function () {
    config(['features.queue_chunk_candles' => 40, 'archive.enabled' => false]);
    app(TickerRepository::class)->saveTickers('kraken', 'BTC/USD', '1m', featureQueueCandles(10));
    $lock = Cache::lock('trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1m'), 720);
    expect($lock->get())->toBeTrue();

    try {
        BuildMarketFeatures::dispatch('kraken', 'BTC/USD', '1m')->onConnection('database');
        $queued = Queue::connection('database')->pop('features');
        expect($queued)->not->toBeNull();

        $queued->fire();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('market_features', 0);
    } finally {
        $lock->release();
    }
});

it('uses a typed exception for feature lock contention', function () {
    $lock = Cache::lock('trademinator:features:'.hash('sha256', 'kraken|BTC/USD|1m'), 720);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(FeatureBuilder::class)->build('kraken', 'BTC/USD', '1m'))
            ->toThrow(FeatureBuildLocked::class, 'Features are already being built for this market and period.');
    } finally {
        $lock->release();
    }
});
