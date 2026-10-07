<?php

use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function mappingUxFixture(string $symbol, string $status): CoinGeckoMarketMapping
{
    $exchange = Exchange::query()->firstOrCreate(['class' => 'ux-test'], ['name' => 'UX Test', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);

    return CoinGeckoMarketMapping::query()->updateOrCreate(['market_id' => $market->market_id], [
        'base_symbol' => explode('/', $symbol)[0], 'vs_currency' => strtolower(explode('/', $symbol)[1]),
        'status' => $status, 'coin_id' => null, 'coin_name' => null, 'manually_mapped' => false,
    ]);
}

it('shows the problematic base symbol and exact CoinGecko candidate identities', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    mappingUxFixture('XRP/USD', 'ambiguous');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'ripple', 'symbol' => 'xrp', 'name' => 'XRP'],
        ['id' => 'another-xrp', 'symbol' => 'xrp', 'name' => 'Another XRP'],
        ['id' => 'bitcoin', 'symbol' => 'btc', 'name' => 'Bitcoin'],
    ])]);

    $this->actingAs($owner)->get(route('owner.coingecko-mappings.index'))->assertOk()
        ->assertSee('Symbol needing identification')->assertSee('XRP/USD')
        ->assertSee('data-symbol="XRP"', false);
    $this->get(route('owner.coingecko-mappings.coins', ['q' => 'XRP', 'exact' => 1]))
        ->assertOk()->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0.id', 'ripple')
        ->assertJsonPath('results.1.id', 'another-xrp');
});

it('keeps a manually saved mapping visible and allows correcting or deleting it', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $mapping = mappingUxFixture('XRP/USD', 'ambiguous');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'ripple', 'symbol' => 'xrp', 'name' => 'XRP'],
        ['id' => 'other-xrp', 'symbol' => 'xrp', 'name' => 'Other XRP'],
    ])]);

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $mapping), ['coin_id' => 'ripple'])->assertRedirect();
    expect($mapping->fresh()->manually_mapped)->toBeTrue();
    $this->get(route('owner.coingecko-mappings.index'))->assertOk()->assertSee('ripple')->assertSee('Update mapping');
    $this->put(route('owner.coingecko-mappings.update', $mapping), ['coin_id' => 'other-xrp'])->assertRedirect();
    expect($mapping->fresh()->coin_id)->toBe('other-xrp');
    $this->delete(route('owner.coingecko-mappings.destroy', $mapping))->assertRedirect();
    expect($mapping->fresh())->toBeNull();
});

it('does not show automatically resolved mappings or allow overriding unsupported quotes', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $automatic = mappingUxFixture('BTC/USD', 'resolved');
    $unsupported = mappingUxFixture('XRP/XYZ', 'unsupported');
    $this->actingAs($owner)->get(route('owner.coingecko-mappings.index'))->assertOk()
        ->assertDontSee('BTC/USD')->assertSee('XRP/XYZ');
    $this->put(route('owner.coingecko-mappings.update', $unsupported), ['coin_id' => 'ripple'])->assertStatus(422);
    expect($automatic->fresh()->manually_mapped)->toBeFalse();
});
