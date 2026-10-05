<?php

use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\WeightedKnn;

function tuningRows(int $count = 180, bool $contradictory = false): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $buy = $i % 2 === 0;
        $rows[] = ['vector' => [$buy ? 0.0 : 1.0], 'label' => $buy ? 'buy' : 'sell',
            'decision_at_ms' => $i * 1000, 'label_available_at_ms' => ($i + 3) * 1000,
            'semantic' => ['bottom' => $contradictory ? ! $buy : $buy, 'top' => $contradictory ? $buy : ! $buy]];
    }

    return $rows;
}

function tuningSettings(): array
{
    return ['min_train_size' => 36, 'test_size' => 12, 'gap' => 2, 'k_cap' => 99,
        'min_validation_rows' => 10, 'min_directional_predictions' => 3,
        'min_semantic_precision' => 0.55, 'min_coverage' => 0.01, 'max_contradiction_rate' => 0.05];
}

it('selects K within the training-only square-root cap and purges unfinished labels', function () {
    $report = (new KnnTuner(new WeightedKnn))->tune(tuningRows(), tuningSettings(), microtime(true) + 10);

    expect($report['k_max'])->toBe(6);
    expect($report['k'])->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);
    expect($report['folds'][0]['train_rows'])->toBe(36);
    expect($report['folds'][1]['train_rows'])->toBe(48);
    expect(end($report['folds'])['train_rows'])->toBeGreaterThan(100);
    foreach ($report['folds'] as $fold) {
        expect($fold['labels_available_by_ms'])->toBeLessThan($fold['test_from_ms']);
    }
});

it('disqualifies semantically contradictory candidates even with perfect classification', function () {
    $report = (new KnnTuner(new WeightedKnn))->tune(tuningRows(180, true), tuningSettings(), microtime(true) + 10);

    expect($report['k'])->toBeNull();
    $scored = array_values(array_filter($report['candidates'], fn ($row) => $row['directional'] > 0));
    expect($scored)->not->toBeEmpty();
    expect($scored[0]['semantic_precision'])->toBe(1);
    expect($scored[0]['contradiction_rate'])->toBe(1);
});

it('refuses an expired compute budget without selecting a partial winner', function () {
    expect(fn () => (new KnnTuner(new WeightedKnn))->tune(tuningRows(), tuningSettings(), microtime(true) - 1))
        ->toThrow(RuntimeException::class, 'time budget');
});
