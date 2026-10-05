<?php

use App\Domain\Intelligence\ValidationGateCalibration;

function calibrationRows(): array
{
    $labels = ['hodl', 'buy', 'sell', 'hodl', 'buy', 'buy', 'buy', 'sell', 'hodl', 'hodl'];
    $rows = [];
    foreach ($labels as $index => $label) {
        $decision = ($index + 1) * 100;
        $rows[] = [
            'decision_at_ms' => $decision,
            'label_available_at_ms' => $decision + 50,
            'label' => $label,
        ];
    }

    return $rows;
}

function calibrationArtifact(): array
{
    return [
        'model_id' => '019dbbbb-bbbb-7bbb-8bbb-bbbbbbbbbbbb',
        'dataset_id' => '019daaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
        'exchange' => 'kraken',
        'symbol' => 'BTC/USD',
        'period' => '1m',
        'status' => 'ready',
        'reason' => 'validated',
        'validation_version' => 'test',
        'holdout_from_ms' => 2000,
        'pattern_keys' => [],
        'lead_lag_keys' => [],
        'human_guidance' => ['influence' => false],
        'candle_guidance' => ['influence' => false],
        'training_data' => ['tuning_rows' => 10],
        'settings' => [
            'train_size' => 5,
            'min_validation_rows' => 5,
            'min_coverage' => 0.01,
            'max_contradiction_rate' => 0.05,
        ],
        'holdout' => [
            'evaluated' => 20,
            'directional' => 10,
            'semantic_precision' => 0.8,
            'coverage' => 0.5,
            'contradiction_rate' => 0.0,
            'eligible' => true,
            'confusion' => [
                'buy' => ['buy' => 4, 'hodl' => 1, 'sell' => 0],
                'hodl' => ['buy' => 1, 'hodl' => 8, 'sell' => 1],
                'sell' => ['buy' => 0, 'hodl' => 1, 'sell' => 4],
            ],
        ],
    ];
}

it('calculates Server-only holdout confidence and a training prediction-mix baseline', function () {
    $calibration = new ValidationGateCalibration;
    $analysis = $calibration->analyze(calibrationArtifact(), calibrationRows());

    expect($analysis['directional'])->toBe(10)
        ->and($analysis['correct_directional'])->toBe(8)
        ->and($analysis['semantic_precision'])->toBe(0.8)
        ->and($analysis['evaluation_training_rows'])->toBe(5)
        ->and($analysis['training_class_counts'])->toBe(['buy' => 2, 'hodl' => 2, 'sell' => 1])
        ->and(abs($analysis['prediction_mix_baseline'] - 0.3))->toBeLessThan(1e-12)
        ->and($analysis['directional_majority_baseline'])->toBe(0.4)
        ->and($analysis['wilson_95_lower'])->toBeGreaterThan(0.49)
        ->and($analysis['wilson_95_lower'])->toBeLessThan(0.50);

    expect($calibration->passes($analysis, 10, 0.75, 0.05, 0.40))->toBeTrue()
        ->and($calibration->passes($analysis, 11, 0.75, 0.05, 0.40))->toBeFalse()
        ->and($calibration->passes($analysis, 10, 0.75, 0.05, 0.50))->toBeFalse();
});

it('refuses calibration when the persisted training chronology cannot be reproduced', function () {
    $artifact = calibrationArtifact();
    $artifact['training_data']['tuning_rows'] = 9;

    expect(fn () => (new ValidationGateCalibration)->analyze($artifact, calibrationRows()))
        ->toThrow(RuntimeException::class, 'Could not reproduce');
});

it('uses all eligible pre-holdout rows for models with an age window', function () {
    $artifact = calibrationArtifact();
    unset($artifact['settings']['train_size']);
    $artifact['settings']['min_train_size'] = 5;
    $artifact['training_data'] = ['tuning_rows' => 8, 'window' => ['from_ms' => 300]];

    $analysis = (new ValidationGateCalibration)->analyze($artifact, calibrationRows());

    expect($analysis['evaluation_training_rows'])->toBe(8)
        ->and($analysis['training_class_counts'])->toBe(['buy' => 3, 'hodl' => 3, 'sell' => 2]);
});

it('keeps independent human training out of automatic calibration chronology', function () {
    $artifact = calibrationArtifact();
    $artifact['ensemble'] = ['version' => 'two-knn-v1'];
    $artifact['automatic'] = ['status' => 'abstaining', 'reason' => 'holdout_failed'];
    $baseline = (new ValidationGateCalibration)->analyze($artifact, calibrationRows());
    $artifact['candle_guidance'] = ['influence' => true, 'mode' => 'independent_knn'];
    expect((new ValidationGateCalibration)->analyze($artifact, calibrationRows()))->toBe($baseline);
    expect($baseline['status'])->toBe('abstaining')->and($baseline['scoring_component'])->toBe('automatic');
});
