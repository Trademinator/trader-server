<?php

use App\Domain\MarketData\TickerHistoryCache;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;

it('reuses bounded decoded OHLCV ranges and invalidates them after writes', function () {
    config([
        'archive.enabled' => false,
        'market_data.history_cache.enabled' => true,
        'market_data.history_cache.store' => 'array',
        'market_data.history_cache.ttl_seconds' => 30,
        'market_data.history_cache.max_rows' => 2000,
    ]);

    $repository = app(TickerRepository::class);
    $from = 1_700_000_000_000;
    $repository->saveTickers('kraken', 'BTC/USD', '1m', [
        ['microtimestamp' => $from, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '5'],
        ['microtimestamp' => $from + 60000, 'open' => '101', 'high' => '103', 'low' => '100', 'close' => '102', 'volume' => '6'],
    ]);

    $first = iterator_to_array(
        $repository->streamHistory('kraken', 'BTC/USD', '1m', $from, $from + 60000),
        true
    );
    expect($first[$from]['close'])->toBe('101');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $cached = iterator_to_array(
        $repository->streamHistory('kraken', 'BTC/USD', '1m', $from, $from + 60000),
        true
    );
    expect($cached)->toBe($first)
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
    $repository->saveTickers('kraken', 'BTC/USD', '1m', [
        ['microtimestamp' => $from, 'open' => '100', 'high' => '104', 'low' => '99', 'close' => '103', 'volume' => '7'],
    ]);

    $corrected = iterator_to_array(
        $repository->streamHistory('kraken', 'BTC/USD', '1m', $from, $from + 60000),
        true
    );
    expect($corrected[$from]['close'])->toBe('103');
});

it('does not materialize oversized history ranges into the shared cache', function () {
    config([
        'market_data.history_cache.enabled' => true,
        'market_data.history_cache.store' => 'array',
        'market_data.history_cache.max_rows' => 2,
    ]);

    expect(app(TickerHistoryCache::class)->shouldCacheRange('1m', 0, 180000))->toBeFalse();
});

it('keys timestamp pages by their upper time boundary', function () {
    config([
        'archive.enabled' => false,
        'market_data.history_cache.enabled' => true,
        'market_data.history_cache.store' => 'array',
        'market_data.history_cache.ttl_seconds' => 30,
    ]);

    $repository = app(TickerRepository::class);
    $first = 1_700_000_000_000;
    $second = $first + 60000;
    $repository->saveTickers('kraken', 'BTC/USD', '1m', [
        ['microtimestamp' => $first, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1'],
        ['microtimestamp' => $second, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '1'],
    ]);

    expect($repository->pageTimestamps('kraken', 'BTC/USD', '1m', 'latest', null, $first, 10))
        ->toBe([$first]);
    expect($repository->pageTimestamps('kraken', 'BTC/USD', '1m', 'latest', null, $second, 10))
        ->toBe([$first, $second]);
});
