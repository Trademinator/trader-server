<?php

use App\Domain\Intelligence\ActionHoldoutCalibration;
use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\ValidationGateCalibration;
use App\Domain\Intelligence\WeightedKnn;

function phase3ActionReport(bool $allHold = false): array
{
    $labels = $allHold
        ? array_fill(0, 200, 'hodl')
        : [...array_fill(0, 20, 'buy'), ...array_fill(0, 160, 'hodl'), ...array_fill(0, 20, 'sell')];
    $rows = $predictions = [];
    foreach ($labels as $i => $label) {
        $rows[] = ['label' => $label, 'decision_at_ms' => ($i + 1) * 1000,
            'label_available_at_ms' => ($i + 2) * 1000,
            'semantic_bottom' => $label === 'buy', 'semantic_top' => $label === 'sell'];
        $action = $label;
        $reason = 'supported';
        if (! $allHold && in_array($i, [0, 1, 180, 181], true)) {
            $action = 'hodl';
            $reason = 'insufficient_effective_neighbors';
        }
        if (! $allHold && in_array($i, [22, 23], true)) {
            $action = 'buy';
        }
        if (! $allHold && in_array($i, [24, 25], true)) {
            $action = 'sell';
        }
        $predictions[] = ['action' => $action, 'reason' => $reason,
            'confidence' => $reason === 'supported' ? 0.95 : 0.0];
    }
    $settings = ['min_validation_rows' => 50, 'min_directional_predictions' => 5,
        'min_directional_opportunities' => 5, 'min_semantic_precision' => 0.55,
        'min_directional_wilson_lower' => 0.55, 'min_directional_baseline_lift' => 0.02,
        'max_contradiction_rate' => 0.05];
    $holdout = (new KnnTuner(new WeightedKnn))->evaluatePredictions($rows, $predictions, $settings);

    return [
        'model_id' => '019dbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
        'dataset_id' => '019daaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'validation_version' => 'm6-outcome-action-knn-v6',
        'status' => 'abstaining', 'reason' => 'outcome_knn_unavailable',
        // Legacy alias is the five-class Outcome report; never interpret as Action.
        'holdout' => ['confusion' => ['super_bear' => ['bull' => 999]]],
        'outcome' => ['algorithmic' => ['status' => 'abstaining', 'reason' => 'holdout_failed']],
        'action' => ['algorithmic' => ['status' => 'ready', 'reason' => 'validated', 'k' => 9, 'holdout' => $holdout]],
    ];
}

it('calibrates independent Action counts without ever reading the five-class Outcome alias', function () {
    $model = phase3ActionReport();
    $calibration = new ValidationGateCalibration;
    $result = $calibration->analyze($model);

    expect($result['scoring_component'])->toBe('action')
        ->and($result['source'])->toBe('algorithmic')
        ->and($result['evaluated'])->toBe(200)
        ->and($result['supported'])->toBe(196)
        ->and($result['abstained'])->toBe(4)
        ->and($result['supported_holds'])->toBe(156)
        ->and($result['directional'])->toBe(40)
        ->and($result['correct_directional'])->toBe(36)
        ->and($result['natural_class_counts'])->toBe(['buy' => 20, 'hodl' => 160, 'sell' => 20])
        ->and($result['baseline_source'])->toBe('observed_finalized_action_holdout')
        ->and($result['coverage_gate_applied'])->toBeFalse()
        ->and($calibration->passes($result, 20, 0.8, 0.1, 0.7))->toBeTrue()
        ->and($calibration->passes($result, 41, 0.8, 0.1, 0.7))->toBeFalse()
        ->and($calibration->passes($result, 20, 0.8, 0.1, 0.8))->toBeFalse();
});

it('reports an all-HOLD holdout as insufficient evidence rather than inventing accuracy', function () {
    $report = ActionHoldoutCalibration::analyze(phase3ActionReport(allHold: true));

    expect($report['directional'])->toBe(0)
        ->and($report['wilson_95_lower'])->toBe(0.0)
        ->and($report['prediction_mix_baseline'])->toBe(0.0)
        ->and($report['validation_status'])->toBe('insufficient_evidence')
        ->and(ActionHoldoutCalibration::passes($report, 1, 0, 0, 0))->toBeFalse();
});

it('refuses inconsistent Action evidence, including supported and abstaining count corruption', function () {
    $report = phase3ActionReport();
    $report['action']['algorithmic']['holdout']['abstained']++;
    expect(fn () => ActionHoldoutCalibration::analyze($report))
        ->toThrow(RuntimeException::class, 'abstained disagrees');

    $report = phase3ActionReport();
    unset($report['action']['algorithmic']['holdout']['abstentions_by_label']);
    expect(fn () => ActionHoldoutCalibration::analyze($report))
        ->toThrow(RuntimeException::class, 'accounting');

    $report = phase3ActionReport();
    unset($report['action']['algorithmic']['holdout']);
    expect(fn () => ActionHoldoutCalibration::analyze($report))
        ->toThrow(RuntimeException::class, 'Phase 2 finalized Action holdout');
});
