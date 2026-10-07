<?php

use App\Domain\Archive\ArchiveIntegrityException;
use App\Domain\Archive\TickerArchive;
use App\Domain\MarketData\CandleGapRepairs;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function recursiveGapStore(array $timestamps, string $period = '15m'): void
{
    app(TickerRepository::class)->saveTickers('bitso', 'ATOM/USD', $period, array_map(fn (int $timestamp): array => [
        'microtimestamp' => $timestamp, 'open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1',
    ], $timestamps));
}

function recursiveGapFeed(string $period = '15m'): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'ATOM/USD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true]);

    return MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
}

it('finds sparse holes through aggregate queries without loading candle payloads', function () {
    $start = strtotime('2026-09-01 UTC') * 1000;
    recursiveGapStore(array_map(fn (int $slot): int => $start + $slot * 900000, array_values(array_diff(range(0, 999), [499, 500, 777]))));
    recursiveGapStore([$start + 499 * 900000], '5m');
    DB::enableQueryLog();

    try {
        $gaps = app(TickerRepository::class)->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', $start + 900_000_000);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, '"tickers"'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($gaps)->toBe([
        ['from' => $start + 449_100_000, 'to' => $start + 450_000_000],
        ['from' => $start + 699_300_000, 'to' => $start + 699_300_000],
    ]);
    expect($queries->count())->toBeLessThan(66);
    expect($queries->implode('\n'))->not->toContain('payload', 'select *');
});

it('does not let an off-grid database row replace a missing expected candle', function () {
    recursiveGapStore([0, 900000, 1000000, 2700000]);

    $gaps = app(TickerRepository::class)->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', 3600000);

    expect($gaps)->toBe([['from' => 1800000, 'to' => 1800000]]);
});

it('reports only closed gaps and starts at the first actual stored candle', function () {
    $start = strtotime('2026-10-05T14:00:00Z') * 1000;
    recursiveGapStore([$start, $start + 1800000, $start + 4500000]);

    $gaps = app(TickerRepository::class)->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', $start + 4800000);

    expect($gaps)->toBe([
        ['from' => $start + 900000, 'to' => $start + 900000],
        ['from' => $start + 2700000, 'to' => $start + 3600000],
    ]);
});

it('handles calendar periods and an empty feed', function () {
    $repository = app(TickerRepository::class);
    expect($repository->missingClosedCandleRanges('bitso', 'ATOM/USD', '1M', strtotime('2024-05-01 UTC') * 1000))->toBe([]);
    recursiveGapStore([strtotime('2024-01-01 UTC') * 1000, strtotime('2024-04-01 UTC') * 1000], '1M');

    $gaps = $repository->missingClosedCandleRanges('bitso', 'ATOM/USD', '1M', strtotime('2024-05-01 UTC') * 1000);

    expect($gaps)->toBe([['from' => strtotime('2024-02-01 UTC') * 1000, 'to' => strtotime('2024-03-01 UTC') * 1000]]);
});

it('prints separate sync commands and includes both neighbours of a single-candle gap', function () {
    $this->travelTo('2026-10-05 16:00:00 UTC');
    $start = strtotime('2026-10-05T14:00:00Z') * 1000;
    recursiveGapFeed();
    recursiveGapStore([$start, $start + 1800000, $start + 4500000, $start + 5400000, $start + 6300000]);

    Artisan::call('trademinator:candle-gaps', ['--exchange' => 'bitso', '--symbol' => 'ATOM/USD', '--period' => '15m']);

    $output = Artisan::output();
    expect($output)->toContain("php artisan trademinator:sync-ohlcv 'bitso' 'ATOM/USD' '15m' --from='2026-10-05T14:00:00Z' --to='2026-10-05T14:30:00Z' --repair-gaps");
    expect($output)->toContain("php artisan trademinator:sync-ohlcv 'bitso' 'ATOM/USD' '15m' --from='2026-10-05T14:45:00Z' --to='2026-10-05T15:00:00Z' --repair-gaps");
    expect(substr_count($output, 'php artisan trademinator:sync-ohlcv'))->toBe(2);
});

it('combines consecutive repair pages into one command while preserving unavailable status', function () {
    $this->travelTo('2026-10-05 14:07:00 UTC');
    config(['history_backfill.page_size' => 2]);
    $start = strtotime('2026-10-05T14:00:00Z') * 1000;
    $feed = recursiveGapFeed('1m');
    recursiveGapStore([$start, $start + 360000], '1m');
    app(CandleGapRepairs::class)->scanFeed($feed);
    DB::table('candle_gap_repairs')->update(['status' => 'unavailable', 'empty_attempts' => 5]);

    Artisan::call('trademinator:candle-gaps', ['--exchange' => 'bitso', '--symbol' => 'ATOM/USD', '--period' => '1m']);

    $output = Artisan::output();
    expect($output)->toContain("--from='2026-10-05T14:01:00Z' --to='2026-10-05T14:05:00Z'");
    expect(substr_count($output, 'php artisan trademinator:sync-ohlcv'))->toBe(1);
    expect(DB::table('candle_gap_repairs')->where('status', 'unavailable')->count())->toBe(3);
});

it('reports a missing cold-history manifest without corrupting transaction state', function () {
    $root = storage_path('framework/testing/recursive-gaps-missing-manifest-'.bin2hex(random_bytes(4)));
    config(['archive.enabled' => true, 'archive.root' => $root, 'archive.gzip_level' => 1]);
    $start = strtotime('2026-01-01 UTC') * 1000;
    recursiveGapStore([$start, $start + 900000], '15m');

    try {
        $result = app(TickerArchive::class)->archiveMonth('bitso', 'ATOM/USD', '15m', 2026, 1);
        DB::table('tickers')->delete();
        unlink($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $result['manifest']));
        $repository = app(TickerRepository::class);
        $repository->invalidateHistory('bitso', 'ATOM/USD', '15m');

        expect(fn () => $repository->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', $start + 1800000))
            ->toThrow(ArchiveIntegrityException::class, 'Archive manifest is missing');
        expect(DB::transactionLevel())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

it('counts archived candles once and still rejects conflicting hot and cold payloads', function () {
    $root = storage_path('framework/testing/recursive-gaps-'.bin2hex(random_bytes(4)));
    config(['archive.enabled' => true, 'archive.root' => $root, 'archive.gzip_level' => 1]);
    $start = strtotime('2026-01-01 UTC') * 1000;
    recursiveGapStore([$start, $start + 900000, $start + 2700000]);

    try {
        app(TickerArchive::class)->archiveMonth('bitso', 'ATOM/USD', '15m', 2026, 1);
        DB::table('tickers')->where('microtimestamp', $start + 900000)->delete();
        $repository = app(TickerRepository::class);
        $repository->invalidateHistory('bitso', 'ATOM/USD', '15m');

        expect($repository->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', $start + 3600000))
            ->toBe([['from' => $start + 1800000, 'to' => $start + 1800000]]);

        DB::table('tickers')->where('microtimestamp', $start)->update(['payload' => '{"microtimestamp":'.$start.',"close":"99"}']);
        $repository->invalidateHistory('bitso', 'ATOM/USD', '15m');
        expect(fn () => $repository->missingClosedCandleRanges('bitso', 'ATOM/USD', '15m', $start + 3600000))
            ->toThrow(ArchiveIntegrityException::class, 'Hot/cold ticker conflict');
    } finally {
        File::deleteDirectory($root);
    }
});
