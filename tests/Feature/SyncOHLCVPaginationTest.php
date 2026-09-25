<?php

use App\Domain\MarketData\CandleGaps;
use App\Domain\MarketData\CandleSyncPages;
use App\Domain\MarketData\MarketDataSynchronizer;
use App\Jobs\SyncMarketCandles;
use App\Models\Exchange;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

it('syncs a long interval in bounded overlapping windows', function () {
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('findByClass')->times(3)->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->times(3)->with($exchange);
    $repository->shouldReceive('periods')->times(3)->andReturn(['1m' => '1m']);
    $repository->shouldReceive('markets')->times(3)->andReturn(['BTC/USD' => []]);
    foreach ([[0, 599], [420, 1019], [840, 1200]] as [$from, $to]) {
        $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', $from, $to, 10)->andReturn([]);
        $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', $from * 1000, $to * 1000)->andReturn([]);
    }

    app()->instance(TickerRepository::class, $tickers);
    app()->instance(MarketDataSynchronizer::class, new MarketDataSynchronizer($repository, $tickers, new CandleGaps));

    $status = Artisan::call('trademinator:sync-ohlcv', [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--from' => '1970-01-01 00:00:00 UTC', '--to' => '1970-01-01 00:20:00 UTC', '--page-size' => '10',
    ]);

    expect($status)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['pages'])->toBe(3);
});

it('queues the next bounded page only after the current page succeeds', function () {
    $exchange = new Exchange(['name' => 'Test', 'class' => 'kraken']);
    $repository = Mockery::mock(ExchangeRepository::class);
    $tickers = Mockery::mock(TickerRepository::class);
    $repository->shouldReceive('findByClass')->once()->with('kraken')->andReturn(new Collection([$exchange]));
    $repository->shouldReceive('setExchange')->once()->with($exchange);
    $repository->shouldReceive('periods')->once()->andReturn(['1m' => '1m']);
    $repository->shouldReceive('markets')->once()->andReturn(['BTC/USD' => []]);
    $repository->shouldReceive('fetch')->once()->with('BTC/USD', '1m', 0, 599, 10)->andReturn([]);
    $tickers->shouldReceive('timestamps')->once()->with('kraken', 'BTC/USD', '1m', 0, 599_000)->andReturn([]);
    $synchronizer = new MarketDataSynchronizer($repository, $tickers, new CandleGaps);

    Bus::fake();
    (new SyncMarketCandles('kraken', 'BTC/USD', '1m', 0, 1200, false, false, 10))
        ->handle($synchronizer, $tickers, new CandleSyncPages);

    Bus::assertDispatched(SyncMarketCandles::class, fn (SyncMarketCandles $job): bool =>
        $job->from === 420 && $job->to === 1200 && $job->pageSize === 10);
});

it('queues only the first job and refuses an inline queue connection', function () {
    Bus::fake();
    $arguments = [
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        '--from' => '1970-01-01 00:00:00 UTC', '--to' => '1970-01-01 00:20:00 UTC',
        '--page-size' => '10', '--queue' => true,
    ];

    config(['queue.default' => 'sync']);
    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(1);
    Bus::assertNotDispatched(SyncMarketCandles::class);

    config(['queue.default' => 'database']);
    expect(Artisan::call('trademinator:sync-ohlcv', $arguments))->toBe(0);
    Bus::assertDispatchedTimes(SyncMarketCandles::class, 1);
    Bus::assertDispatched(SyncMarketCandles::class, fn (SyncMarketCandles $job): bool =>
        $job->from === 0 && $job->to === 1200 && $job->pageSize === 10);
});
