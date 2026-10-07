<?php

use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function mappingThrottleFixture(): array
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $exchange = Exchange::query()->create([
        'name' => 'Throttle Test', 'class' => 'throttle-test', 'config' => '{}',
    ]);
    $market = Market::query()->create([
        'exchange_id' => $exchange->exchange_id,
        'symbol' => 'XYZ/USD',
        'tick_size' => '0.01',
    ]);

    // Creating a market may automatically insert its pending mapping.
    $mapping = CoinGeckoMarketMapping::query()->updateOrCreate(
        ['market_id' => $market->market_id],
        [
            'base_symbol' => 'XYZ',
            'vs_currency' => 'usd',
            'status' => 'unmapped',
            'coin_id' => null,
            'coin_name' => null,
            'last_error' => 'No exact CoinGecko symbol match.',
        ],
    );

    return [$owner, $mapping];
}

it('allows a manual mapping update after numerous owner page views', function () {
    [$owner, $mapping] = mappingThrottleFixture();
    Cache::forget('trademinator:coingecko:coins-list');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'ripple', 'name' => 'XRP', 'symbol' => 'xrp'],
    ])]);

    $this->actingAs($owner);
    for ($i = 0; $i < 15; $i++) {
        $this->get(route('owner.coingecko-mappings.index'))->assertOk();
    }

    $this->put(route('owner.coingecko-mappings.update', $mapping), [
        'coin_id' => 'ripple',
    ])->assertRedirect();
    expect($mapping->fresh()->status)->toBe('resolved')
        ->and($mapping->fresh()->coin_id)->toBe('ripple')
        ->and($mapping->fresh()->manually_mapped)->toBeTrue();
});

it('does not exhaust the search limit after half its advertised requests', function () {
    [$owner, $mapping] = mappingThrottleFixture();
    Cache::forget('trademinator:coingecko:coins-list');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([
        ['id' => 'ripple', 'name' => 'XRP', 'symbol' => 'xrp'],
    ])]);

    $this->actingAs($owner);
    for ($i = 0; $i < 18; $i++) {
        $this->get(route('owner.coingecko-mappings.coins', ['q' => 'rip']))
            ->assertOk()
            ->assertJsonPath('results.0.id', 'ripple');
    }

    $this->put(route('owner.coingecko-mappings.update', $mapping), [
        'coin_id' => 'ripple',
    ])->assertRedirect();
    expect($mapping->fresh()->coin_id)->toBe('ripple');
});

it('retains a separate twelve-write-per-minute safety limit', function () {
    [$owner, $mapping] = mappingThrottleFixture();
    $this->actingAs($owner);

    // Failed form validation still counts as an attempted write.
    for ($i = 0; $i < 12; $i++) {
        $this->put(route('owner.coingecko-mappings.update', $mapping), [
            'coin_id' => '',
        ])->assertSessionHasErrors('coin_id');
    }
    $this->put(route('owner.coingecko-mappings.update', $mapping), [
        'coin_id' => '',
    ])->assertStatus(429);
});
