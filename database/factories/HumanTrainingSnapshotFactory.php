<?php

namespace Database\Factories;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\NormalizedVector;
use App\Models\HumanTrainingSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HumanTrainingSnapshot>
 */
class HumanTrainingSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $timestamp = 1577836800000 + fake()->unique()->numberBetween(100, 100000) * 60000;
        $payload = ['version' => HumanTraining::VERSION, 'feature_version' => FeatureEngine::VERSION,
            'normalization' => NormalizedVector::VERSION, 'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
            'microtimestamp' => $timestamp, 'decision_at_ms' => $timestamp + 60000, 'horizon_candles' => 2,
            'keys' => ['candle.body'], 'vector' => [0.5], 'features' => ['candle.body' => 0.5], 'feature_sha256' => null,
            'series' => array_map(fn (int $time): array => ['time' => intdiv($time, 1000), 'open' => '10', 'high' => '11', 'low' => '9', 'close' => '10', 'volume' => '1'], [$timestamp - 60000, $timestamp]),
            'gaps' => 0, 'patterns' => [], 'model_observation' => null];

        return ['payload' => $payload, 'version' => HumanTraining::VERSION, 'dataset_id' => (string) Str::uuid7(),
            'market_key' => ModelStore::marketKey('kraken', 'BTC/USD', '1m'),
            'decision_at_ms' => fn (array $attributes): int => $attributes['payload']['decision_at_ms'],
            'snapshot_key' => fn (array $attributes): string => hash('sha256', $attributes['market_key'].'|'.$attributes['decision_at_ms'].'|'.HumanTraining::VERSION),
            'sha256' => fn (array $attributes): string => HumanTrainingSnapshot::digest($attributes['payload']), 'created_at' => now()];
    }
}
