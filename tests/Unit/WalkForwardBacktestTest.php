<?php

use App\Domain\Features\FeatureEngine;

use App\Domain\Research\BaselineBacktester;
use App\Domain\Research\WalkForward;

function researchRows(int $count = 16): array
{
    return array_map(fn ($i) => ['decision_at_ms' => ($i + 1) * 60000, 'entry_at_ms' => ($i + 1) * 60000,
        'label_available_at_ms' => ($i + 3) * 60000, 'vector' => [1], 'label' => 'buy', 'buy_net_return' => -0.01], range(0, $count - 1));
}

function researchManifest(): array
{
    return ['dataset_id' => 'test', 'rows_sha256' => 'hash', 'keys' => ['trend.direction'],
        'feature_version' => FeatureEngine::VERSION, 'label_definition' => ['horizon' => 2]];
}

it('purges labels reaching the validation boundary and never shuffles or overlaps test blocks', function () {
    $rows = researchRows();
    $folds = iterator_to_array((new WalkForward)->folds($rows, 3, 4));
    expect($folds[0]['train'])->toBe([0, 1, 2])->and($folds[0]['test'])->toBe([5, 6, 7, 8]);
    $tested = [];
    foreach ($folds as $fold) {
        foreach ($fold['train'] as $index) {
            expect($rows[$index]['label_available_at_ms'])->toBeLessThan($rows[$fold['test'][0]]['decision_at_ms']);
        }
        $tested = array_merge($tested, $fold['test']);
    }
    expect(count($tested))->toBe(count(array_unique($tested)))->and($tested)->toBe(range(5, 15));
});

it('supports an additional row gap and fixed rolling training windows', function () {
    $folds = iterator_to_array((new WalkForward)->folds(researchRows(), 3, 4, 4, false));
    expect($folds[0]['train'])->toBe([0, 1, 2])->and($folds[0]['test'][0])->toBe(7)
        ->and($folds[1]['train'])->toBe([4, 5, 6])->and($folds[0]['purged_or_gap_rows'])->toBe(4);
});

it('fits the majority baseline only to mature training labels', function () {
    $rows = researchRows();
    $original = (new BaselineBacktester)->run(researchManifest(), $rows, 'majority', 3, 4);
    foreach (range(3, 15) as $index) {
        $rows[$index]['label'] = 'sell';
    }
    $changed = (new BaselineBacktester)->run(researchManifest(), $rows, 'majority', 3, 4);
    expect($changed['folds'][0]['majority_label'])->toBe('buy')
        ->and(array_column(array_slice($changed['predictions'], 0, 4), 'prediction'))
        ->toBe(array_column(array_slice($original['predictions'], 0, 4), 'prediction'));
});

it('does not compound overlapping horizons even across fold boundaries', function () {
    $result = (new BaselineBacktester)->run(researchManifest(), researchRows(), 'buy', 3, 3);
    expect($result['portfolio']['trades'])->toBe(6)
        ->and($result['portfolio']['overlapping_buy_signals_skipped'])->toBe(5)
        ->and($result['portfolio']['equity'])->toEqualWithDelta(0.99 ** 6, 1e-12)
        ->and($result['portfolio']['max_drawdown'])->toEqualWithDelta(1 - 0.99 ** 6, 1e-12)
        ->and($result['classification']['samples'])->toBe(11);
});

it('keeps sell and hodl in cash and reports no undefined win rate as a number', function () {
    $rows = researchRows();
    foreach ($rows as &$row) {
        $row['vector'] = [-1];
    }
    $result = (new BaselineBacktester)->run(researchManifest(), $rows, 'trend', 3, 4);
    expect($result['portfolio']['trades'])->toBe(0)->and($result['portfolio']['win_rate'])->toBeNull()
        ->and($result['portfolio']['equity'])->toBe(1.0)->and($result['predictions'][0]['prediction'])->toBe('sell');
});

it('rejects insufficient history invalid strategies and unsorted rows', function () {
    expect(fn () => (new BaselineBacktester)->run(researchManifest(), researchRows(3), 'majority', 3, 4))->toThrow(RuntimeException::class)
        ->and(fn () => (new BaselineBacktester)->run(researchManifest(), researchRows(), 'knn', 3, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn () => iterator_to_array((new WalkForward)->folds(array_reverse(researchRows()), 3, 4)))->toThrow(InvalidArgumentException::class);
});
