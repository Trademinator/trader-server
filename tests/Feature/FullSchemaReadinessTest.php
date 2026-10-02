<?php

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Models\CoinGeckoMarketMapping;
use App\Models\Exchange;
use App\Models\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

function fullSchemaReadinessFeatures(array $missing = []): void
{
    $timeframe = new CandleTimeframe;
    $timestamp = IntelligenceFixtures::START;
    $values = array_fill_keys(FeatureSchema::keys('full'), 0.5);
    $values['trend.direction'] = $values['candle.direction'] = 0;
    foreach ($missing as $key) {
        $values[$key] = null;
    }
    $available = $timeframe->next($timestamp, '1m');
    DB::table('market_features')->insert([
        'feature_id' => (string) Str::uuid7(),
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        'version' => FeatureEngine::VERSION,
        'microtimestamp' => $timestamp,
        'available_at_ms' => $available,
        'payload' => json_encode([
            'version' => FeatureEngine::VERSION,
            'microtimestamp' => $timestamp,
            'available_at_ms' => $available,
            'features' => $values,
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function fullSchemaReadinessMapping(string $status = 'resolved', ?string $error = null): void
{
    $exchange = Exchange::query()->firstOrCreate(
        ['class' => 'kraken'],
        ['name' => 'Kraken', 'config' => '{}'],
    );
    $market = Market::query()->firstOrCreate(
        ['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD'],
        ['tick_size' => '0.01'],
    );
    CoinGeckoMarketMapping::query()->updateOrCreate(
        ['market_id' => $market->market_id],
        [
            'base_symbol' => 'BTC',
            'vs_currency' => 'usd',
            'coin_id' => $status === 'resolved' ? 'bitcoin' : null,
            'coin_name' => $status === 'resolved' ? 'Bitcoin' : null,
            'status' => $status,
            'last_error' => $error,
        ],
    );
}

function fullSchemaReadiness(): array
{
    return app(IntelligenceReadiness::class)->describe(
        'kraken',
        'BTC/USD',
        '1m',
        null,
        null,
        WeightedKnn::abstain('no_model'),
    );
}

beforeEach(function () {
    config(['queue.default' => 'database', 'intelligence.schema' => 'full']);
    $this->travelTo('2024-01-01 00:01:00 UTC');
});

it('shows partial CoinGecko context while keeping technical intelligence available', function () {
    fullSchemaReadinessFeatures(['context.category_momentum', 'context.circulating_fraction']);
    fullSchemaReadinessMapping();

    $full = fullSchemaReadiness()['full_schema'];

    expect($full['context_available'])->toBe(8)
        ->and($full['context_total'])->toBe(count(ContextFeatures::KEYS))
        ->and($full['missing'])->toBe(['context.category_momentum', 'context.circulating_fraction'])
        ->and($full['technical_ready'])->toBeTrue()
        ->and($full['technical_missing'])->toBe([])
        ->and($full['full_ready'])->toBeFalse()
        ->and($full['mapping']['status'])->toBe('resolved')
        ->and($full['mapping']['coin_id'])->toBe('bitcoin');
});

it('reports an unmapped CoinGecko asset without disabling the technical schema', function () {
    fullSchemaReadinessFeatures(ContextFeatures::KEYS);
    fullSchemaReadinessMapping('unmapped', 'CoinGecko returned no exact symbol match.');

    $full = fullSchemaReadiness()['full_schema'];

    expect($full['context_available'])->toBe(0)
        ->and($full['missing'])->toBe(ContextFeatures::KEYS)
        ->and($full['technical_ready'])->toBeTrue()
        ->and($full['full_ready'])->toBeFalse()
        ->and($full['mapping']['status'])->toBe('unmapped')
        ->and($full['mapping']['error'])->toBe('CoinGecko returned no exact symbol match.');
});

it('marks the strict full schema ready only when all technical and context features are present', function () {
    fullSchemaReadinessFeatures();
    fullSchemaReadinessMapping();

    $full = fullSchemaReadiness()['full_schema'];

    expect($full['context_available'])->toBe(10)
        ->and($full['missing'])->toBe([])
        ->and($full['technical_ready'])->toBeTrue()
        ->and($full['full_ready'])->toBeTrue();
});
