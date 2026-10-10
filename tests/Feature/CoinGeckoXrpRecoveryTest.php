<?php

use App\Domain\Features\ContextFeatures;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function coinGeckoBulkFixture(string $exchangeClass, string $symbol, string $status,
    ?string $coinId = null): CoinGeckoMarketMapping
{
    $exchange = Exchange::query()->firstOrCreate(['class' => $exchangeClass],
        ['name' => ucfirst($exchangeClass), 'config' => '{}']);
    $market = Market::query()->create([
        'exchange_id' => $exchange->getKey(), 'symbol' => $symbol, 'tick_size' => '0.01',
    ]);

    return CoinGeckoMarketMapping::query()->updateOrCreate(['market_id' => $market->getKey()], [
        'base_symbol' => explode('/', $symbol)[0],
        'vs_currency' => strtolower(explode('/', $symbol)[1]),
        'status' => $status, 'coin_id' => $coinId, 'coin_name' => null,
        'category' => null, 'manually_mapped' => false,
    ]);
}

it('can explicitly reuse XRP identity for unresolved supported quotes without changing existing decisions', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    $source = coinGeckoBulkFixture('bitso', 'XRP/USD', 'ambiguous');
    $cad = coinGeckoBulkFixture('kraken', 'XRP/CAD', 'unmapped');
    $mxn = coinGeckoBulkFixture('bitso', 'XRP/MXN', 'pending');
    $unsupported = coinGeckoBulkFixture('coinbase', 'XRP/XYZ', 'unsupported');
    $other = coinGeckoBulkFixture('kraken', 'ATOM/USD', 'ambiguous');
    $alreadyResolved = coinGeckoBulkFixture('coinbase', 'XRP/EUR', 'resolved', 'ripple');
    Http::preventStrayRequests();
    Http::fake([
        '*/coins/list' => Http::response([['id' => 'ripple', 'symbol' => 'xrp', 'name' => 'XRP']]),
        '*/simple/supported_vs_currencies' => Http::response(['usd', 'cad', 'mxn', 'eur']),
        '*/coins/ripple*' => Http::response(['categories' => ['Layer 1 (L1)']]),
        '*/coins/categories/list' => Http::response([
            ['category_id' => 'layer-1', 'name' => 'Layer 1 (L1)'],
        ]),
    ]);

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $source),
        ['coin_id' => 'ripple', 'apply_same_base' => '1'])
        ->assertRedirect()
        ->assertSessionHas('coingecko_mapping_feedback.total', 3)
        ->assertSessionHas('coingecko_mapping_feedback.additional', 2)
        ->assertSessionHas('coingecko_mapping_feedback.coin_id', 'ripple');

    foreach ([$source, $cad, $mxn] as $mapping) {
        expect($mapping->fresh()->coin_id)->toBe('ripple')
            ->and($mapping->fresh()->status)->toBe('resolved')
            ->and($mapping->fresh()->manually_mapped)->toBeTrue()
            ->and($mapping->fresh()->category)->toBe('layer-1');
    }
    expect($unsupported->fresh()->status)->toBe('unsupported')
        ->and($unsupported->fresh()->coin_id)->toBeNull()
        ->and($other->fresh()->status)->toBe('ambiguous')
        ->and($alreadyResolved->fresh()->coin_id)->toBe('ripple')
        ->and($alreadyResolved->fresh()->manually_mapped)->toBeFalse();
});

it('rejects bulk remapping when another resolved market uses a conflicting identity', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    $source = coinGeckoBulkFixture('bitso', 'XRP/USD', 'ambiguous');
    $conflict = coinGeckoBulkFixture('kraken', 'XRP/CAD', 'resolved', 'another-xrp');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([['id' => 'ripple', 'symbol' => 'xrp', 'name' => 'XRP']])]);

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $source),
        ['coin_id' => 'ripple', 'apply_same_base' => '1'])->assertSessionHasErrors('coin_id');

    expect($source->fresh()->status)->toBe('ambiguous')
        ->and($conflict->fresh()->coin_id)->toBe('another-xrp');
});

it('rejects bulk propagation of a coin with a mismatched ticker', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    $source = coinGeckoBulkFixture('bitso', 'XRP/USD', 'ambiguous');
    Http::preventStrayRequests();
    Http::fake(['*/coins/list' => Http::response([['id' => 'bitcoin', 'symbol' => 'btc', 'name' => 'Bitcoin']])]);

    $this->actingAs($owner)->put(route('owner.coingecko-mappings.update', $source),
        ['coin_id' => 'bitcoin', 'apply_same_base' => '1'])->assertSessionHasErrors('coin_id');

    expect($source->fresh()->status)->toBe('ambiguous');
});

it('treats historical trends and category as optional while requiring fresh core observations', function () {
    $at = 1_783_456_000_000;
    $snapshot = [
        'snapshot_id' => 'test-xrp-observation',
        'vs_currency' => 'usd',
        'observed_at_ms' => $at - 1000,
        'payload' => [
            'expires_at_ms' => $at + 3600000,
            'coin' => ['current_price' => 2, 'market_cap' => 1000, 'total_volume' => 100],
            'global' => [
                'market_cap_change_percentage_24h_usd' => 2,
                'market_cap_percentage' => ['btc' => 50],
                'total_market_cap' => ['usd' => 100000],
                'total_volume' => ['usd' => 50000],
            ],
            'btc_dominance_change' => null, 'activity_deviation' => null,
            'categories' => [], 'category_expires_at_ms' => [],
        ],
    ];

    $context = (new ContextFeatures)->calculate($snapshot, 2.0, $at, 7200000);
    expect($context['context_ready'])->toBeTrue()
        ->and($context['features']['context.btc_dominance_change'])->toBeNull()
        ->and($context['features']['context.activity_deviation'])->toBeNull()
        ->and($context['features']['context.category_momentum'])->toBeNull();
    $snapshot['payload']['coin']['market_cap'] = null;
    expect((new ContextFeatures)->calculate($snapshot, 2.0, $at, 7200000)['context_ready'])->toBeFalse();
});

it('shows an explicit single-market save when no unresolved XRP markets remain', function () {
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->getKey()]);
    $mapping = coinGeckoBulkFixture('bitso', 'XRP/USD', 'ambiguous');
    Http::preventStrayRequests();
    Http::fake([
        '*/coins/list' => Http::response([['id' => 'ripple', 'symbol' => 'xrp', 'name' => 'XRP']]),
        '*/simple/supported_vs_currencies' => Http::response(['usd']),
        '*/coins/ripple*' => Http::response(['categories' => []]),
    ]);

    $this->actingAs($owner)
        ->put(route('owner.coingecko-mappings.update', $mapping),
            ['coin_id' => 'ripple', 'apply_same_base' => '1'])
        ->assertRedirect()
        ->assertSessionHas('coingecko_mapping_feedback.total', 1)
        ->assertSessionHas('coingecko_mapping_feedback.additional', 0)
        ->assertSessionHas('coingecko_mapping_feedback.bulk_requested', true);
    $this->get(route('owner.coingecko-mappings.index'))
        ->assertOk()
        ->assertSee('No additional unresolved markets needed updating.')
        ->assertSee('Mapping saved: XRP');
});
