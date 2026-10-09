<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function autoLabelCommandMarket(string $exchangeClass, string $symbol): void
{
    $exchange = Exchange::query()->firstOrCreate(
        ['class' => $exchangeClass],
        ['name' => ucfirst($exchangeClass), 'config' => '{}']
    );
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id,
        'symbol' => $symbol,
        'tick_size' => '0.01',
    ]);
    MarketFeed::query()->create([
        'market_id' => $market->market_id,
        'selected_period' => '15m',
        'status' => 'active',
    ]);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->user_id,
        'market_id' => $market->market_id,
        'active' => true,
    ]);
}

beforeEach(function () {
    config([
        'exchange_fees.taker_overrides.bitso.rate' => 0.0,
        'exchange_fees.taker_overrides.kraken.rate' => 0.0,
    ]);
    autoLabelCommandMarket('bitso', 'ATOM/USD');
    autoLabelCommandMarket('bitso', 'BTC/USD');
    autoLabelCommandMarket('kraken', 'BTC/USD');
});

it('manually runs auto-label analysis globally by exchange and by exchange pair', function () {
    expect(Artisan::call('trademinator:auto-label', ['--json' => true]))->toBe(0);
    $global = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($global)->toHaveCount(3);

    expect(Artisan::call('trademinator:auto-label', ['exchange' => 'bitso', '--json' => true]))->toBe(0);
    $exchange = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exchange)->toHaveCount(2)
        ->and(array_unique(array_column($exchange, 'exchange')))->toBe(['bitso']);

    expect(Artisan::call('trademinator:auto-label', [
        'exchange' => 'bitso',
        'symbol' => 'ATOM/USD',
        '--json' => true,
    ]))->toBe(0);
    $pair = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($pair)->toHaveCount(1)
        ->and($pair[0]['exchange'])->toBe('bitso')
        ->and($pair[0]['symbol'])->toBe('ATOM/USD')
        ->and($pair[0]['status'])->toBe('insufficient_distance_observations')
        ->and($pair[0]['distance_observations'])->toBe(0);

    $report = DB::table('market_action_label_analyses')->where('exchange', 'bitso')
        ->where('symbol', 'ATOM/USD')->where('period', '15m')->first();
    expect($report)->not->toBeNull()
        ->and(json_decode($report->analysis, true, flags: JSON_THROW_ON_ERROR)['action_counts'])
        ->toBe($pair[0]['action_counts']);
});
