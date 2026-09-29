<?php

use App\Domain\Features\DerivedMarketHistory;
use App\Domain\MarketData\ClosedCandleAggregator;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\Ticker;
use Illuminate\Support\Facades\DB;

it('aggregates exact decimal volume and excludes gapped or unfinished buckets', function () {
    $bars = [];
    for ($i = 0; $i < 15; $i++) {
        $bars[] = ['microtimestamp' => $i * 60000, 'open' => '1', 'high' => '2', 'low' => '0.5', 'close' => '1.5',
            'volume' => '0.000000000000000001'];
    }
    unset($bars[7]);
    $result = iterator_to_array((new ClosedCandleAggregator)->rows($bars, '1m', '5m', 14 * 60000));

    expect($result)->toHaveCount(1);
    expect($result[0]['volume'])->toBe('0.000000000000000005');
    expect($result[0]['microtimestamp'])->toBe(0);
    expect(fn () => iterator_to_array((new ClosedCandleAggregator)->rows($bars, '1m', '1M', 99999999)))
        ->toThrow(InvalidArgumentException::class, 'fixed');
});

it('reuses the M2 pipeline for derived periods and removes buckets whose source becomes incomplete', function () {
    $this->travelTo('2024-01-01 01:00:00 UTC');
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m', 'status' => 'active']);
    $start = 1704067200000;
    for ($i = 0; $i < 15; $i++) {
        Ticker::query()->create(['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => $start + $i * 60000, 'payload' => json_encode([
                'open' => '10', 'close' => '11', 'high' => '12', 'low' => '9', 'volume' => '1'])]);
    }

    $first = app(DerivedMarketHistory::class)->build('kraken', 'BTC/USD', '1m', '5m', $start, $start + 14 * 60000);
    expect($first['candles'])->toBe(2);
    expect(DB::table('market_features')->where('period', '5m')->count())->toBe(2);
    Ticker::query()->where('period', '1m')->where('microtimestamp', $start + 7 * 60000)->delete();

    $second = app(DerivedMarketHistory::class)->build('kraken', 'BTC/USD', '1m', '5m', $start, $start + 14 * 60000);

    expect($second['candles'])->toBe(1);
    expect(Ticker::query()->where('period', '5m')->count())->toBe(1);
    expect(DB::table('market_features')->where('period', '5m')->count())->toBe(1);
    $existing = Ticker::query()->where('period', '5m')->first();
    $existing->update(['payload' => json_encode(['open' => '10', 'close' => '11', 'high' => '12', 'low' => '9', 'volume' => '1'])]);
    expect(fn () => app(DerivedMarketHistory::class)->build('kraken', 'BTC/USD', '1m', '5m', $start))
        ->toThrow(InvalidArgumentException::class, 'not be overwritten');
    expect(Ticker::query()->where('period', '5m')->count())->toBe(1);
});
