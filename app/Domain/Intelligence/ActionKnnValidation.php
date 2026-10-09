<?php

namespace App\Domain\Intelligence;

/**
 * Natural-frequency, evidence-aware assessment of finalized Action labels.
 * No rows are resampled or relabelled. Abstentions are excluded from the
 * supported confusion matrix but remain in the historical opportunity counts.
 */
final class ActionKnnValidation
{
    private const Z_95 = 1.959963984540054;

    public static function assess(array $report, array $settings): array
    {
        $matrix = $report['confusion'];
        $abstentions = $report['abstentions_by_label'];
        $evaluated = (int) $report['evaluated'];
        $directional = (int) $report['directional'];
        $correct = (int) $report['correct'];

        $actual = [];
        foreach (['buy', 'hodl', 'sell'] as $label) {
            $actual[$label] = array_sum($matrix[$label]) + $abstentions[$label];
        }
        $opportunities = $actual['buy'] + $actual['sell'];
        $classDiversity = count(array_filter($actual, static fn (int $count): bool => $count > 0));
        $byAction = [];
        $baselineNumerator = 0.0;

        foreach (['buy', 'sell'] as $action) {
            $predicted = array_sum(array_column($matrix, $action));
            $tp = $matrix[$action][$action];
            $falsePositives = $predicted - $tp;
            $negatives = $evaluated - $actual[$action];
            $prevalence = $evaluated > 0 ? (float) $actual[$action] / $evaluated : null;
            $baselineNumerator += $predicted * ($prevalence ?? 0.0);
            $byAction[$action] = [
                'actual_opportunities' => $actual[$action],
                'predicted' => $predicted,
                'correct' => $tp,
                'false_positives' => $falsePositives,
                // HOLD, opposite-action predictions and abstentions all miss an opportunity.
                'missed_opportunities' => $actual[$action] - $tp,
                'abstentions_on_opportunities' => $abstentions[$action],
                'precision' => $predicted > 0 ? (float) $tp / $predicted : null,
                'recall' => $actual[$action] > 0 ? (float) $tp / $actual[$action] : null,
                'false_positive_rate' => $negatives > 0 ? (float) $falsePositives / $negatives : null,
                'natural_prevalence' => $prevalence,
                'precision_wilson_95' => self::wilson95($tp, $predicted),
            ];
        }

        $baseline = $directional > 0 ? $baselineNumerator / $directional : null;
        $wilson = self::wilson95($correct, $directional);
        $requiredLower = max(
            (float) ($settings['min_directional_wilson_lower'] ?? 0.55),
            ($baseline ?? 0.0) + (float) ($settings['min_directional_baseline_lift'] ?? 0.02)
        );
        $evidenceGates = [
            'validation_rows' => $evaluated >= (int) $settings['min_validation_rows'],
            'directional_opportunities' => $opportunities >= (int) ($settings['min_directional_opportunities'] ?? $settings['min_directional_predictions']),
            'directional_predictions' => $directional >= (int) $settings['min_directional_predictions'],
            'historical_class_diversity' => $classDiversity >= 2,
        ];
        $qualityGates = [
            'semantic_precision' => $directional > 0 && $report['semantic_precision'] >= $settings['min_semantic_precision'],
            'directional_wilson_lower' => $wilson !== null && $wilson['lower'] >= $requiredLower,
            'contradiction_rate' => $directional > 0 && $report['contradiction_rate'] <= $settings['max_contradiction_rate'],
        ];
        $failedEvidence = array_keys(array_filter($evidenceGates, static fn (bool $passed): bool => ! $passed));
        $failedQuality = array_keys(array_filter($qualityGates, static fn (bool $passed): bool => ! $passed));
        $status = $failedEvidence !== [] ? 'insufficient_evidence' : ($failedQuality !== [] ? 'failed' : 'validated');

        return [
            'eligible' => $status === 'validated',
            'validation_status' => $status,
            'validation_reason' => match ($status) {
                'validated' => 'validated',
                'insufficient_evidence' => 'insufficient_directional_evidence',
                default => 'quality_gates_failed',
            },
            'evidence_gates' => $evidenceGates,
            'quality_gates' => $qualityGates,
            'gates' => [...$evidenceGates, ...$qualityGates],
            'failed_evidence_gates' => $failedEvidence,
            'failed_quality_gates' => $failedQuality,
            'failed_gates' => [...$failedEvidence, ...$failedQuality],
            'natural_class_counts' => $actual,
            'directional_opportunities' => $opportunities,
            'directional_wilson_95' => $wilson,
            'required_directional_wilson_lower' => $requiredLower,
            'prediction_mix_baseline' => $baseline,
            'precision_lift_over_baseline' => $baseline === null ? null : $report['semantic_precision'] - $baseline,
            'by_action' => $byAction,
            'coverage_gate_applied' => false,
            'validation_policy' => 'action-natural-prevalence-wilson-v1',
        ];
    }

    /** null means no observations, not a numerical zero-confidence estimate. */
    public static function wilson95(int $successes, int $total): ?array
    {
        if ($total <= 0) {
            return null;
        }
        $p = $successes / $total;
        $z2 = self::Z_95 ** 2;
        $denominator = 1 + $z2 / $total;
        $center = $p + $z2 / (2 * $total);
        $margin = self::Z_95 * sqrt($p * (1 - $p) / $total + $z2 / (4 * $total * $total));

        return [
            'lower' => max(0.0, ($center - $margin) / $denominator),
            'upper' => min(1.0, ($center + $margin) / $denominator),
        ];
    }
}
