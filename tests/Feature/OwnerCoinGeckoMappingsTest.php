<?php

use App\Domain\Features\CoinGeckoMappingManager;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function ownerCoinGeckoMappingFixture(string $exchangeClass, string $symbol, string $status): CoinGeckoMarketMapping
{
    $exchange = Exchange::query()->firstOrCreate(
        ['class' => $exchangeClass], ['name' => ucfirst($exchangeClass), 'config' => '{}'],
    );
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01',
    ]);
    MarketSubscription::query()->create([
        'user_id' => User::factory()->create()->user_id, 'market_id' => $market->market_id, 'active' => true,
    ]);

    // Market creation can already insert a pending mapping via its event observer.
    return CoinGeckoMarketMapping::query()->updateOrCreate(
        ['market_id' => $market->market_id],
        [
            'base_symbol' => explode('/', $symbol, 2)[0],
            'vs_currency' => strtolower(explode('/', $symbol, 2)[1]),
            'coin_id' => null, 'coin_name' => null, 'status' => $status,
            'category' => null, 'resolved_at' => null,
            'last_error' => 'CoinGecko could not resolve the symbol automatically.',
        ],
    );
}

function ownerCoinGeckoUser(): User
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    return $owner;
}

it('restricts the CoinGecko mapping endpoints to the server owner', function () {
    $owner = ownerCoinGeckoUser();
    $mapping = ownerCoinGeckoMappingFixture('bitso', 'XYZ/USD', 'unmapped');
    $stranger = User::factory()->create();

    $this->get(route('owner.coingecko-mappings.index'))->assertRedirect('/login');
    $this->actingAs($stranger)->get(route('owner.coingecko-mappings.index'))->assertForbidden();
    $this->get(route('owner.coingecko-mappings.coins', ['q' => 'bitcoin']))->assertForbidden();
    $this->put(route('owner.coingecko-mappings.update', $mapping), ['coin_id' => 'bitcoin'])->assertForbidden();
    $this->delete(route('owner.coingecko-mappings.destroy', $mapping))->assertForbidden();

    expect($mapping->fresh()->status)->toBe('unmapped');
    $this->actingAs($owner)->get(route('owner.coingecko-mappings.index'))->assertOk();
});

it('shows only mappings requiring owner attention and identifies their exchange', function () {
    $owner = ownerCoinGeckoUser();
    ownerCoinGeckoMappingFixture('bitso', 'XYZ/USD', 'unmapped');
    ownerCoinGeckoMappingFixture('kraken', 'ABC/CAD', 'ambiguous');
    ownerCoinGeckoMappingFixture('coinbase', 'BTC/XYZ', 'unsupported');
    ownerCoinGeckoMappingFixture('gemini', 'ETH/USD', 'resolved');

    $this->actingAs($owner)->get(route('owner.coingecko-mappings.index'))
        ->assertOk()
        ->assertSee('XYZ/USD')
        ->assertSee('ABC/CAD')
        ->assertSee('BTC/XYZ')
        ->assertDontSee('ETH/USD')
        ->assertSee('Bitso')
        ->assertSee('Kraken');
});

it('searches CoinGecko coins and saves an exact catalogue ID without touching another exchange', function () {
    $owner = ownerCoinGeckoUser();
    $mapping = ownerCoinGeckoMappingFixture('bitso', 'XYZ/USD', 'unmapped');
    $other = ownerCoinGeckoMappingFixture('kraken', 'XYZ/USD', 'ambiguous');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'actual-asset', 'symbol' => 'abc', 'name' => 'Actual Asset'],
        ['id' => 'bitcoin', 'symbol' => 'btc', 'name' => 'Bitcoin'],
    ])]);

    $this->actingAs($owner)->get(route('owner.coingecko-mappings.coins', ['q' => 'actual']))
        ->assertOk()->assertJsonPath('results.0.id', 'actual-asset');
    $this->put(route('owner.coingecko-mappings.update', $mapping), ['coin_id' => 'actual-asset'])
        ->assertRedirect();

    expect($mapping->fresh()->status)->toBe('resolved')
        ->and($mapping->fresh()->coin_id)->toBe('actual-asset')
        ->and($mapping->fresh()->coin_name)->toBe('Actual Asset')
        ->and($mapping->fresh()->last_error)->toBeNull()
        ->and($other->fresh()->status)->toBe('ambiguous')
        ->and($other->fresh()->coin_id)->toBeNull();

    // Reconciliation must respect the existing manually chosen identity.
    app(CoinGeckoMappingManager::class)->ensureForActiveMarkets();
    expect($mapping->fresh()->coin_id)->toBe('actual-asset')
        ->and($mapping->fresh()->status)->toBe('resolved');
});

it('refuses unknown CoinGecko IDs instead of accepting arbitrary submitted values', function () {
    $owner = ownerCoinGeckoUser();
    $mapping = ownerCoinGeckoMappingFixture('bitso', 'XYZ/USD', 'unmapped');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'bitcoin', 'symbol' => 'btc', 'name' => 'Bitcoin'],
    ])]);

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $mapping), [
        'coin_id' => 'not-a-real-id',
    ])->assertSessionHasErrors('coin_id');
    expect($mapping->fresh()->coin_id)->toBeNull()
        ->and($mapping->fresh()->status)->toBe('unmapped');
});

it('never allows an unsupported exact quote to be overridden through coin selection', function () {
    $owner = ownerCoinGeckoUser();
    $mapping = ownerCoinGeckoMappingFixture('coinbase', 'BTC/XYZ', 'unsupported');
    Http::preventStrayRequests();

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $mapping), [
        'coin_id' => 'bitcoin',
    ])->assertStatus(422);
    expect($mapping->fresh()->status)->toBe('unsupported')
        ->and($mapping->fresh()->coin_id)->toBeNull();
    Http::assertNothingSent();
});

it('deletes only the exception and permits automatic recreation without touching snapshots', function () {
    $owner = ownerCoinGeckoUser();
    $mapping = ownerCoinGeckoMappingFixture('bitso', 'XYZ/USD', 'unmapped');
    $marketId = $mapping->market_id;
    $snapshotId = (string) Str::uuid7();
    DB::table('market_context_snapshots')->insert([
        'snapshot_id' => $snapshotId, 'coin_id' => 'bitcoin', 'vs_currency' => 'usd',
        'observed_at_ms' => now()->getTimestampMs(), 'payload' => '{}',
    ]);

    $this->actingAs($owner)->delete(route('owner.coingecko-mappings.destroy', $mapping))
        ->assertRedirect();
    expect(CoinGeckoMarketMapping::query()->where('market_id', $marketId)->exists())->toBeFalse()
        ->and(Market::query()->where('market_id', $marketId)->exists())->toBeTrue();
    $this->assertDatabaseHas('market_context_snapshots', ['snapshot_id' => $snapshotId]);

    app(CoinGeckoMappingManager::class)->ensureForActiveMarkets();
    expect(CoinGeckoMarketMapping::query()->where('market_id', $marketId)->count())->toBe(1)
        ->and(CoinGeckoMarketMapping::query()->where('market_id', $marketId)->first()->status)->toBe('pending');
    $this->assertDatabaseHas('market_context_snapshots', ['snapshot_id' => $snapshotId]);
});
