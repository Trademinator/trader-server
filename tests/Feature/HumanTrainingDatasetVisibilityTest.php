<?php

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('finds a subscribed market dataset behind more than 100 newer unrelated datasets', function () {
    $this->travelTo('2026-10-01 12:00:00 UTC');
    $targetId = (string) Str::uuid7();
    $base = [
        'period' => '5m',
        'feature_version' => FeatureEngine::VERSION,
        'label_definition' => ['version' => SemanticLabels::VERSION],
        'as_of_ms' => now()->subHour()->getTimestampMs(),
    ];
    DB::table('research_datasets')->insert([
        'dataset_id' => $targetId,
        'manifest' => json_encode([...$base, 'dataset_id' => $targetId, 'exchange' => 'bitso', 'symbol' => 'BTC/USD']),
        'created_at' => now()->subDay(),
    ]);

    for ($i = 0; $i < 105; $i++) {
        $id = (string) Str::uuid7();
        DB::table('research_datasets')->insert([
            'dataset_id' => $id,
            'manifest' => json_encode([...$base, 'dataset_id' => $id, 'exchange' => 'kraken', 'symbol' => 'ETH/USD']),
            'created_at' => now()->subMinutes(105 - $i),
        ]);
    }

    $datasets = app(HumanTraining::class)->datasets([
        ['exchange_class' => 'bitso', 'pair' => 'BTC/USD', 'period' => '5m'],
    ]);

    expect($datasets)->toHaveCount(1)
        ->and($datasets[0]['dataset_id'])->toBe($targetId);
});
