<?php

use App\Domain\Intelligence\ActionLabelReportStore;
use Illuminate\Support\Facades\DB;

it('keeps the newest published market analysis and never persists per-candle private arrays', function () {
    $store = app(ActionLabelReportStore::class);
    $latest = [
        'as_of_ms' => 2000, 'from_ms' => 1000, 'status' => 'validated',
        'action_counts' => ['buy' => 12, 'hold' => 20, 'sell' => 12],
        '_action_labels' => [123 => 'buy'],
        '_action_available_at_ms' => [123 => 456],
    ];
    $store->publish('bitso', 'ATOM/USD', '15m', $latest, '00000000-0000-4000-8000-000000000001');
    $store->publish('bitso', 'ATOM/USD', '15m', [
        'as_of_ms' => 1000, 'action_counts' => ['buy' => 1, 'hold' => 2, 'sell' => 1],
    ]);

    $published = $store->latest('bitso', 'ATOM/USD', '15m');
    expect($published['as_of_ms'])->toBe(2000)
        ->and($published['source'])->toBe('dataset')
        ->and($published['analysis']['action_counts'])->toBe(['buy' => 12, 'hold' => 20, 'sell' => 12])
        ->and(isset($published['analysis']['_action_labels']))->toBeFalse();

    $store->publish('bitso', 'ATOM/USD', '15m', [
        'as_of_ms' => 3000, 'action_counts' => ['buy' => 13, 'hold' => 19, 'sell' => 12],
    ]);
    expect($store->latest('bitso', 'ATOM/USD', '15m')['analysis']['action_counts'])
        ->toBe(['buy' => 13, 'hold' => 19, 'sell' => 12]);
    expect(DB::table('market_action_label_analyses')->count())->toBe(1);
});
