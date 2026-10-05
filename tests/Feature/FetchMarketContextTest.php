<?php

use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['features.coingecko.enabled' => true, 'features.coingecko.api_key' => 'test-key']);
});

function createManualContextMapping(string $exchangeClass = 'bitso', string $symbol = 'ATOM/USD',
    string $coinId = 'cosmos', string $currency = 'usd', ?string $category = 'layer-1'): CoinGeckoMarketMapping
{
    $exchange = Exchange::query()->firstOrCreate(['class' => $exchangeClass], ['name' => ucfirst($exchangeClass), 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);

    return CoinGeckoMarketMapping::query()->create([
        'market_id' => $market->market_id, 'base_symbol' => explode('/', $symbol)[0],
        'coin_id' => $coinId, 'vs_currency' => $currency, 'category' => $category, 'status' => 'resolved',
    ]);
}

function fakeManualContextResponse(array $coinChanges = []): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api.coingecko.com/api/v3/global' => Http::response(['data' => [
            'updated_at' => now()->getTimestamp(), 'market_cap_percentage' => ['btc' => 50],
        ]]),
        'api.coingecko.com/api/v3/coins/categories' => Http::response([
            ['id' => 'layer-1', 'updated_at' => now()->toIso8601String(), 'market_cap_change_24h' => 1.5],
        ]),
        'api.coingecko.com/api/v3/coins/markets*' => Http::response([array_merge([
            'id' => 'cosmos', 'current_price' => 2, 'market_cap' => 1000, 'total_volume' => 100,
            'circulating_supply' => 534372000, 'max_supply' => null, 'last_updated' => now()->toIso8601String(),
        ], $coinChanges)]),
    ]);
}

it('fetches one mapped coin and exact quote immediately even after a same-hour snapshot', function () {
    $this->travelTo('2026-10-05 16:35:00 UTC');
    createManualContextMapping();
    createManualContextMapping('kraken', 'ATOM/USD');
    createManualContextMapping('bitso', 'ATOM/CAD', currency: 'cad');
    createManualContextMapping('bitso', 'BTC/USD', 'bitcoin');
    $previousId = (string) Str::uuid7();
    $previousPayload = json_encode(['coin' => ['market_cap' => 900, 'total_volume' => 90], 'global' => []]);
    DB::table('market_context_snapshots')->insert([
        'snapshot_id' => $previousId, 'coin_id' => 'cosmos', 'vs_currency' => 'usd',
        'observed_at_ms' => now()->subMinutes(5)->getTimestampMs(), 'payload' => $previousPayload,
    ]);
    Bus::fake();
    fakeManualContextResponse();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'USD'])
        ->expectsOutputToContain('Stored 1 context snapshot(s). No jobs were queued.')->assertSuccessful();

    $this->assertDatabaseCount('market_context_snapshots', 2);
    $this->assertDatabaseHas('market_context_snapshots', ['snapshot_id' => $previousId, 'payload' => $previousPayload]);
    $snapshot = DB::table('market_context_snapshots')->where('snapshot_id', '!=', $previousId)->first();
    expect($snapshot)->coin_id->toBe('cosmos')->vs_currency->toBe('usd')
        ->observed_at_ms->toBe(now()->getTimestampMs());
    expect(json_decode($snapshot->payload, true)['coin']['max_supply'])->toBeNull();
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/coins/markets')
        && $request['ids'] === 'cosmos' && $request['vs_currency'] === 'usd');
    Bus::assertNothingDispatched();
});

it('fetches the selected pair without a subscription or a queue worker', function () {
    $this->travelTo('2026-10-05 16:35:00 UTC');
    createManualContextMapping();
    createManualContextMapping('kraken', 'ATOM/CAD', currency: 'cad');
    Bus::fake();
    fakeManualContextResponse();

    $this->artisan('trademinator:fetch-market-context', ['--exchange' => 'bitso', '--symbol' => 'ATOM/USD'])
        ->expectsOutputToContain('Fetching cosmos / USD synchronously...')->assertSuccessful();

    $this->assertDatabaseCount('market_context_snapshots', 1);
    $this->assertDatabaseHas('market_context_snapshots', ['coin_id' => 'cosmos', 'vs_currency' => 'usd']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/coins/markets')
        && $request['ids'] === 'cosmos' && $request['vs_currency'] === 'usd');
    Bus::assertNothingDispatched();
});

it('rejects incomplete or mixed selectors before any request or dispatch', function (array $options) {
    Http::preventStrayRequests();
    Bus::fake();

    $this->artisan('trademinator:fetch-market-context', $options)
        ->expectsOutput('Use either --coin with --vs-currency, or --exchange with --symbol.')->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
})->with([
    'no selector' => [[]],
    'coin without quote' => [['--coin' => 'cosmos']],
    'quote without coin' => [['--vs-currency' => 'usd']],
    'exchange without symbol' => [['--exchange' => 'bitso']],
    'symbol without exchange' => [['--symbol' => 'ATOM/USD']],
    'mixed selectors' => [['--coin' => 'cosmos', '--vs-currency' => 'usd', '--exchange' => 'bitso', '--symbol' => 'ATOM/USD']],
]);

it('rejects an unresolved or unknown mapping without collecting other coins', function (string $status, string $coinId) {
    createManualContextMapping()->update(['status' => $status]);
    Http::preventStrayRequests();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => $coinId, '--vs-currency' => 'usd'])
        ->expectsOutput('No resolved CoinGecko mapping matches that selection. Resolve the market mapping first.')
        ->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertNothingSent();
})->with(['pending' => ['pending', 'cosmos'], 'unknown' => ['resolved', 'unknown-coin']]);

it('rejects a mapping with the wrong quote instead of substituting USD', function () {
    createManualContextMapping(symbol: 'ATOM/USDT', currency: 'usd');
    Http::preventStrayRequests();

    $this->artisan('trademinator:fetch-market-context', ['--exchange' => 'bitso', '--symbol' => 'ATOM/USDT'])
        ->expectsOutput('CoinGecko mappings require a coin ID and the exact spot quote currency.')->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertNothingSent();
});

it('fails clearly when context collection is not configured', function (array $settings, string $message) {
    createManualContextMapping();
    config($settings);
    Http::preventStrayRequests();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])
        ->expectsOutput($message)->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertNothingSent();
})->with([
    'disabled' => [['features.coingecko.enabled' => false], 'Set COINGECKO_ENABLED=true before fetching context.'],
    'missing key' => [['features.coingecko.api_key' => null], 'Set COINGECKO_API_KEY before fetching context.'],
]);

it('honours the existing collector lock without releasing another process lock', function () {
    createManualContextMapping();
    Http::preventStrayRequests();
    $lock = Cache::lock('trademinator:coingecko-context', 3600);
    $lock->get();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])
        ->expectsOutput('Context collection is already running. Retry after it finishes.')->assertFailed();

    expect($lock->isOwnedByCurrentProcess())->toBeTrue();
    $lock->release();
    Http::assertNothingSent();
    $this->assertDatabaseCount('market_context_snapshots', 0);
});

it('does not store stale or unrelated coin responses', function (array $coinChanges) {
    $this->travelTo('2026-10-05 16:35:00 UTC');
    createManualContextMapping();
    fakeManualContextResponse($coinChanges);

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])
        ->expectsOutput('CoinGecko returned no fresh data for this coin/quote. No snapshot was stored.')->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertSentCount(3);
})->with([
    'stale' => [['last_updated' => '2026-10-04T16:35:00+00:00']],
    'another coin' => [['id' => 'bitcoin']],
]);

it('fails on provider errors and releases its lock for a later retry', function () {
    createManualContextMapping();
    Http::preventStrayRequests();
    Http::fake(['api.coingecko.com/api/v3/global' => Http::response([], 429)]);

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])->assertFailed();

    $this->assertDatabaseCount('market_context_snapshots', 0);
    Http::assertSentCount(1);
    $lock = Cache::lock('trademinator:coingecko-context', 3600);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('counts distinct prior hours and retains the daily anchor despite repeated manual refreshes', function () {
    $this->travelTo('2026-10-05 16:35:00 UTC');
    createManualContextMapping();
    for ($i = 0; $i < 200; $i++) {
        DB::table('market_context_snapshots')->insert([
            'snapshot_id' => (string) Str::uuid7(), 'coin_id' => 'cosmos', 'vs_currency' => 'usd',
            'observed_at_ms' => now()->subHour()->getTimestampMs() - $i * 1000,
            'payload' => json_encode(['coin' => ['market_cap' => 1000, 'total_volume' => 100],
                'global' => ['market_cap_percentage' => ['btc' => 49]]]),
        ]);
    }
    DB::table('market_context_snapshots')->insert([
        'snapshot_id' => (string) Str::uuid7(), 'coin_id' => 'cosmos', 'vs_currency' => 'usd',
        'observed_at_ms' => now()->subDay()->addMinutes(5)->getTimestampMs(),
        'payload' => json_encode(['coin' => ['market_cap' => 1000, 'total_volume' => 100],
            'global' => ['market_cap_percentage' => ['btc' => 46]]]),
    ]);
    DB::table('market_context_snapshots')->insert([
        'snapshot_id' => (string) Str::uuid7(), 'coin_id' => 'cosmos', 'vs_currency' => 'usd',
        'observed_at_ms' => now()->subDay()->getTimestampMs(),
        'payload' => json_encode(['coin' => ['market_cap' => 1000, 'total_volume' => 100],
            'global' => ['market_cap_percentage' => ['btc' => 45]]]),
    ]);
    fakeManualContextResponse();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])->assertSuccessful();

    $payload = json_decode(DB::table('market_context_snapshots')->orderByDesc('observed_at_ms')->value('payload'), true);
    expect($payload['activity_deviation'])->toBeNull();
    expect($payload['btc_dominance_change'])->toBe(5);
    Http::assertSentCount(3);
});

it('keeps activity context available after twenty-four distinct hourly observations', function () {
    $this->travelTo('2026-10-05 16:35:00 UTC');
    createManualContextMapping();
    for ($i = 1; $i <= 24; $i++) {
        DB::table('market_context_snapshots')->insert([
            'snapshot_id' => (string) Str::uuid7(), 'coin_id' => 'cosmos', 'vs_currency' => 'usd',
            'observed_at_ms' => now()->subHours($i)->getTimestampMs(),
            'payload' => json_encode(['coin' => ['market_cap' => 1000, 'total_volume' => 100],
                'global' => ['market_cap_percentage' => ['btc' => 49]]]),
        ]);
    }
    fakeManualContextResponse();

    $this->artisan('trademinator:fetch-market-context', ['--coin' => 'cosmos', '--vs-currency' => 'usd'])->assertSuccessful();

    $payload = json_decode(DB::table('market_context_snapshots')->orderByDesc('observed_at_ms')->value('payload'), true);
    expect($payload['activity_deviation'])->not->toBeNull();
    expect($payload['btc_dominance_change'])->toBe(1);
    Http::assertSentCount(3);
});
