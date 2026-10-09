<?php

use App\Domain\Intelligence\KnnTuner;
use App\Domain\Intelligence\WeightedKnn;

function p2HistoricalRow(string $label, int $position): array
{
    return ['decision_at_ms' => $position * 1000, 'label_available_at_ms' => ($position + 2) * 1000,
        'label' => $label, 'semantic_bottom' => $label === 'buy', 'semantic_top' => $label === 'sell'];
}

function p2Prediction(string $action, bool $supported = true): array
{
    return ['action' => $supported ? $action : 'hodl',
        'reason' => $supported ? 'supported' : 'no_similar_history',
        'confidence' => $supported ? 0.95 : 0.0];
}

function p2Settings(): array
{
    return ['min_validation_rows' => 50, 'min_directional_opportunities' => 5,
        'min_directional_predictions' => 5, 'min_semantic_precision' => 0.55,
        'min_directional_wilson_lower' => 0.55, 'min_directional_baseline_lift' => 0.02,
        'min_coverage' => 0.01, 'max_contradiction_rate' => 0.05];
}

function p2Score(array $labels, ?array $predictions = null): array
{
    $rows = array_map(static fn (string $label, int $i): array => p2HistoricalRow($label, $i + 1),
        $labels, array_keys($labels));
    $predictions ??= array_map(static fn (string $label): array => p2Prediction($label), $labels);

    return (new KnnTuner(new WeightedKnn))->evaluatePredictions($rows, $predictions, p2Settings());
}

it('treats four perfect trades among many HOLDs as insufficient evidence, not bad accuracy', function () {
    $labels = [...array_fill(0, 2, 'buy'), ...array_fill(0, 96, 'hodl'), ...array_fill(0, 2, 'sell')];
    $report = p2Score($labels);

    expect($report['semantic_precision'])->toBe(1)
        ->and($report['natural_class_counts'])->toBe(['buy' => 2, 'hodl' => 96, 'sell' => 2])
        ->and($report['directional_opportunities'])->toBe(4)
        ->and($report['directional'])->toBe(4)
        ->and($report['validation_status'])->toBe('insufficient_evidence')
        ->and($report['validation_reason'])->toBe('insufficient_directional_evidence')
        ->and($report['failed_evidence_gates'])->toContain('directional_opportunities', 'directional_predictions')
        ->and($report['eligible'])->toBeFalse();
});

it('validates six correct sparse signals without forcing one percent directional coverage', function () {
    $labels = [...array_fill(0, 3, 'buy'), ...array_fill(0, 994, 'hodl'), ...array_fill(0, 3, 'sell')];
    $report = p2Score($labels);

    expect($report['coverage'])->toBe(0.006)
        ->and($report['coverage_gate_applied'])->toBeFalse()
        ->and($report['validation_status'])->toBe('validated')
        ->and($report['eligible'])->toBeTrue()
        ->and($report['supported_holds'])->toBe(994)
        ->and($report['by_action']['buy']['precision'])->toBe(1.0)
        ->and($report['by_action']['sell']['recall'])->toBe(1.0)
        ->and($report['by_action']['buy']['false_positive_rate'])->toBe(0.0)
        ->and($report['by_action']['sell']['natural_prevalence'])->toBe(0.003)
        ->and($report['directional_wilson_95']['lower'])->toBeGreaterThan(0.55);
});

it('does not validate a classifier that only emits supported HOLD or abstention', function () {
    $labels = [...array_fill(0, 10, 'buy'), ...array_fill(0, 80, 'hodl'), ...array_fill(0, 10, 'sell')];
    $report = p2Score($labels, array_fill(0, 100, p2Prediction('hodl')));

    expect($report['supported_holds'])->toBe(100)
        ->and($report['directional'])->toBe(0)
        ->and($report['validation_status'])->toBe('insufficient_evidence')
        ->and($report['directional_wilson_95'])->toBeNull()
        ->and($report['by_action']['buy']['precision'])->toBeNull()
        ->and($report['by_action']['sell']['missed_opportunities'])->toBe(10);

    $abstaining = p2Score($labels, array_fill(0, 100, p2Prediction('hodl', false)));
    expect($abstaining['supported_holds'])->toBe(0)
        ->and($abstaining['abstained'])->toBe(100)
        ->and($abstaining['natural_class_counts'])->toBe(['buy' => 10, 'hodl' => 80, 'sell' => 10])
        ->and($abstaining['validation_status'])->toBe('insufficient_evidence');
});

it('fails quality when BUY predictions incorrectly trigger on historical HOLD candles', function () {
    $labels = [...array_fill(0, 6, 'buy'), ...array_fill(0, 88, 'hodl'), ...array_fill(0, 6, 'sell')];
    $predictions = array_map(static fn (string $label): array => p2Prediction($label), $labels);
    for ($i = 6; $i < 10; $i++) {
        $predictions[$i] = p2Prediction('buy');
    }
    $report = p2Score($labels, $predictions);

    expect($report['directional'])->toBe(16)
        ->and($report['correct'])->toBe(12)
        ->and($report['by_action']['buy']['false_positives'])->toBe(4)
        ->and($report['by_action']['buy']['false_positive_rate'])->toBe(4 / 94)
        ->and($report['by_action']['buy']['precision'])->toBe(0.6)
        ->and($report['validation_status'])->toBe('failed')
        ->and($report['failed_quality_gates'])->toContain('directional_wilson_lower')
        ->and($report['eligible'])->toBeFalse();
});

it('requires more than one historical class instead of approving a one-class imitation', function () {
    $report = p2Score(array_fill(0, 100, 'buy'));

    expect($report['directional'])->toBe(100)
        ->and($report['validation_status'])->toBe('insufficient_evidence')
        ->and($report['failed_evidence_gates'])->toContain('historical_class_diversity')
        ->and($report['prediction_mix_baseline'])->toBe(1.0);
});

it('counts abstained BUY opportunities as missed without altering the supported confusion matrix', function () {
    $labels = [...array_fill(0, 5, 'buy'), ...array_fill(0, 90, 'hodl'), ...array_fill(0, 5, 'sell')];
    $predictions = array_map(static fn (string $label): array => p2Prediction($label), $labels);
    $predictions[0] = p2Prediction('hodl', false);
    $report = p2Score($labels, $predictions);

    expect($report['natural_class_counts'])->toBe(['buy' => 5, 'hodl' => 90, 'sell' => 5])
        ->and($report['abstentions_by_label']['buy'])->toBe(1)
        ->and($report['confusion']['buy']['hodl'])->toBe(0)
        ->and($report['by_action']['buy']['missed_opportunities'])->toBe(1)
        ->and($report['by_action']['buy']['abstentions_on_opportunities'])->toBe(1)
        ->and($report['by_action']['buy']['recall'])->toBe(0.8);
});
