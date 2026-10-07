<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function candleGapConcurrencyFeed(string $period = '5m'): MarketFeed
{
    $exchange = Exchange::query()->create(['name' => 'Bitso', 'class' => 'bitso', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'XRP/USD', 'tick_size' => '0.0001']);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id,
        'active' => true,
    ]);

    return MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => $period]);
}

function candleGapConcurrencyStore(array $timestamps, string $period = '5m'): void
{
    app(TickerRepository::class)->saveTickers('bitso', 'XRP/USD', $period, array_map(fn (int $timestamp): array => [
        'microtimestamp' => $timestamp,
        'open' => '1',
        'high' => '1.1',
        'low' => '0.9',
        'close' => '1',
        'volume' => '10',
    ], $timestamps));
}

it('reports a busy gap scan instead of racing a market worker', function () {
    $this->travelTo('2026-10-07 16:30:00 UTC');
    $feed = candleGapConcurrencyFeed();
    $start = strtotime('2026-10-07T15:00:00Z') * 1000;
    candleGapConcurrencyStore([$start, $start + 600000]);

    $lock = Cache::lock('trademinator:market-feed:'.$feed->market_id, 720);
    expect($lock->get())->toBeTrue();

    try {
        Artisan::call('trademinator:candle-gaps', [
            '--exchange' => 'bitso',
            '--symbol' => 'XRP/USD',
            '--period' => '5m',
        ]);

        $output = Artisan::output();

        expect($output)
            ->toContain('SCAN BUSY')
            ->toContain('Another market-data worker is currently updating this feed.')
            ->toContain('No confirmed candle-gap problems were detected.')
            ->toContain('1 active feed could not be scanned because a market-data worker was updating it.')
            ->not->toContain('need attention')
            ->not->toContain('SCAN ERROR');

        expect(DB::table('candle_gap_repairs')->count())->toBe(0);
    } finally {
        $lock->release();
    }
});
