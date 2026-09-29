<?php

namespace Database\Factories;

use App\Domain\Intelligence\WeightedKnn;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketSignal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MarketSignal> */
class MarketSignalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'market_id' => fn () => Market::query()->create([
                'exchange_id' => Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}'])->getKey(),
                'symbol' => 'BTC/USD', 'tick_size' => '0.01',
            ])->getKey(),
            'snapshot_key' => hash('sha256', (string) Str::uuid7()), 'period' => '1m', 'model_id' => null,
            'decision_at_ms' => null, 'recorded_at_ms' => now()->getTimestampMs(),
            'is_change' => true, 'action' => 'hodl', 'reason' => 'no_model',
            'payload' => [...WeightedKnn::abstain('no_model'), 'execution_status' => 'unknown'],
        ];
    }
}
