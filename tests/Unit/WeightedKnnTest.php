<?php

use App\Domain\Intelligence\NormalizedVector;
use App\Domain\Intelligence\WeightedKnn;

it('normalizes directional coordinates identically without changing ordinary features', function () {
    expect(NormalizedVector::from([-1, 0, 0.7], ['trend.direction', 'candle.direction', 'candle.body']))
        ->toBe([0.0, 0.5, 0.7]);
    expect(fn () => NormalizedVector::from([INF], ['candle.body']))->toThrow(InvalidArgumentException::class);
    expect(fn () => NormalizedVector::from([2], ['trend.direction']))->toThrow(InvalidArgumentException::class);
});

it('weights nearby examples and excludes labels unavailable at decision time', function () {
    $rows = [
        ['vector' => [0.1], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.11], 'label' => 'buy', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
        ['vector' => [0.8], 'label' => 'sell', 'decision_at_ms' => 3, 'label_available_at_ms' => 4],
        ['vector' => [0.0], 'label' => 'sell', 'decision_at_ms' => 4, 'label_available_at_ms' => 10],
    ];

    $result = (new WeightedKnn(1, 1.5, 0.5))->predict($rows, [0.0], 3, 10);

    expect($result['action'])->toBe('buy');
    expect($result['neighbors'])->toBe(3);
    expect($result['confidence'])->toBeGreaterThan(0.75);
});

it('abstains on distant evidence ties and too few effective exact neighbors', function () {
    $knn = new WeightedKnn(0.2, 2, 0.5);
    $rows = [
        ['vector' => [0.1], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2],
        ['vector' => [0.1], 'label' => 'sell', 'decision_at_ms' => 2, 'label_available_at_ms' => 3],
    ];

    expect($knn->predict($rows, [0.9], 2, 10)['reason'])->toBe('no_similar_history');
    expect($knn->predict($rows, [0.1], 2, 10)['reason'])->toBe('tied_votes');
    $rows[1]['vector'] = [0.11];
    $result = $knn->predict($rows, [0.1], 2, 10);
    expect($result['reason'])->toBe('insufficient_effective_neighbors');
    expect($result['confidence'])->toBe(0.0);
    expect($result['action'])->toBe('hodl');
});

it('rejects incompatible dimensions and malformed evidence thresholds', function () {
    $rows = [['vector' => [0.1, 0.5], 'label' => 'buy', 'decision_at_ms' => 1, 'label_available_at_ms' => 2]];
    expect(fn () => (new WeightedKnn)->predict($rows, [0.1], 3, 10))->toThrow(InvalidArgumentException::class);
    expect(fn () => new WeightedKnn(NAN))->toThrow(InvalidArgumentException::class);
});
