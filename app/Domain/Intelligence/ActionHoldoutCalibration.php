<?php

namespace App\Domain\Intelligence;

use RuntimeException;

/**
 * Read-only calibration of the persisted algorithmic Action KNN holdout.
 * Outcome's legacy top-level holdout has five classes and must never be used here.
 * A candidate grid is research, NOT permission to retune on the final holdout.
 */
final class ActionHoldoutCalibration
{
    private const ACTIONS = ['buy', 'hodl', 'sell'];

    public static function analyze(array $report): array
    {
        $algorithmic = $report['action']['algorithmic'] ?? null;
        $holdout = $algorithmic['holdout'] ?? null;
        if (! is_array($holdout) || ($holdout['validation_policy'] ?? null) !== 'action-natural-prevalence-wilson-v1'
            || ($holdout['evaluation_basis'] ?? null) !== 'finalized_historical_action_labels') {
            throw new RuntimeException('Model has no Phase 2 finalized Action holdout to calibrate; rebuild it first.');
        }

        $matrix = $holdout['confusion'] ?? null;
        $abstentions = $holdout['abstentions_by_label'] ?? null;
        if (! is_array($matrix) || ! is_array($abstentions)) {
            throw new RuntimeException('Saved Action holdout is missing supported/abstention accounting.');
        }

        $supported = $abstained = 0;
        $actual = [];
        foreach (self::ACTIONS as $label) {
            if (! is_array($matrix[$label] ?? null)
                || ! is_int($abstentions[$label] ?? null) || $abstentions[$label] < 0) {
                throw new RuntimeException('Saved Action holdout contains an invalid label count.');
            }
            $actual[$label] = $abstentions[$label];
            $abstained += $abstentions[$label];
            foreach (self::ACTIONS as $prediction) {
                $n = $matrix[$label][$prediction] ?? null;
                if (! is_int($n) || $n < 0) {
                    throw new RuntimeException('Saved Action holdout contains an invalid confusion count.');
                }
                $actual[$label] += $n;
                $supported += $n;
            }
        }

        $evaluated = $supported + $abstained;
        $predictedBuy = array_sum(array_column($matrix, 'buy'));
        $predictedSell = array_sum(array_column($matrix, 'sell'));
        $predictedHold = array_sum(array_column($matrix, 'hodl'));
        $directional = $predictedBuy + $predictedSell;
        $correct = $matrix['buy']['buy'] + $matrix['sell']['sell'];
        $precision = $directional ? $correct / $directional : 0.0;
        $coverage = $evaluated ? $directional / $evaluated : 0.0;
        $baseline = $directional && $evaluated
            ? ($predictedBuy * $actual['buy'] + $predictedSell * $actual['sell']) / ($directional * $evaluated)
            : null;
        $wilson = ActionKnnValidation::wilson95($correct, $directional);

        foreach (['evaluated' => $evaluated, 'supported' => $supported, 'abstained' => $abstained,
            'directional' => $directional, 'correct' => $correct, 'supported_holds' => $predictedHold,
            'correct_holds' => $matrix['hodl']['hodl']] as $field => $expected) {
            if (($holdout[$field] ?? null) !== $expected) {
                throw new RuntimeException('Saved Action holdout '.$field.' disagrees with its confusion/abstention counts.');
            }
        }
        foreach (['semantic_precision' => $precision, 'coverage' => $coverage] as $field => $expected) {
            if (! is_numeric($holdout[$field] ?? null) || abs((float) $holdout[$field] - $expected) > 1e-9) {
                throw new RuntimeException('Saved Action holdout '.$field.' disagrees with its confusion counts.');
            }
        }
        if (($holdout['natural_class_counts'] ?? null) !== $actual
            || ($holdout['directional_opportunities'] ?? null) !== $actual['buy'] + $actual['sell']
            || ($baseline === null && ($holdout['prediction_mix_baseline'] ?? null) !== null)
            || ($baseline !== null && (! is_numeric($holdout['prediction_mix_baseline'] ?? null)
                || abs((float) $holdout['prediction_mix_baseline'] - $baseline) > 1e-9))) {
            throw new RuntimeException('Saved Action holdout natural frequencies or baseline are inconsistent.');
        }
        if (($wilson === null && ($holdout['directional_wilson_95'] ?? null) !== null)
            || ($wilson !== null && (! is_numeric($holdout['directional_wilson_95']['lower'] ?? null)
                || abs((float) $holdout['directional_wilson_95']['lower'] - $wilson['lower']) > 1e-9))) {
            throw new RuntimeException('Saved Action holdout Wilson interval is inconsistent.');
        }

        $evidence = $holdout['evidence_gates'] ?? null;
        $quality = $holdout['quality_gates'] ?? null;
        if (! is_array($evidence) || ! is_array($quality)) {
            throw new RuntimeException('Saved Action holdout lacks separate evidence and quality gates.');
        }
        foreach (['validation_rows', 'directional_opportunities', 'directional_predictions', 'historical_class_diversity'] as $gate) {
            if (! is_bool($evidence[$gate] ?? null)) {
                throw new RuntimeException('Saved Action evidence gate is missing: '.$gate);
            }
        }
        foreach (['semantic_precision', 'directional_wilson_lower', 'contradiction_rate'] as $gate) {
            if (! is_bool($quality[$gate] ?? null)) {
                throw new RuntimeException('Saved Action quality gate is missing: '.$gate);
            }
        }

        return [
            'model_id' => $report['model_id'] ?? null,
            'dataset_id' => $report['dataset_id'] ?? null,
            'exchange' => $report['exchange'] ?? null,
            'symbol' => $report['symbol'] ?? null,
            'period' => $report['period'] ?? null,
            'scoring_component' => 'action',
            'source' => 'algorithmic',
            'status' => $algorithmic['status'] ?? null,
            'reason' => $algorithmic['reason'] ?? null,
            'validation_version' => $report['validation_version'] ?? null,
            'validation_status' => $holdout['validation_status'] ?? null,
            'evaluated' => $evaluated,
            'supported' => $supported,
            'abstained' => $abstained,
            'supported_holds' => $predictedHold,
            'correct_holds' => $matrix['hodl']['hodl'],
            'directional' => $directional,
            'correct_directional' => $correct,
            'semantic_precision' => $precision,
            'wilson_95_lower' => $wilson['lower'] ?? 0.0,
            'coverage' => $coverage,
            'coverage_gate_applied' => false,
            'contradiction_rate' => (float) ($holdout['contradiction_rate'] ?? 0.0),
            'predicted_buy' => $predictedBuy,
            'predicted_sell' => $predictedSell,
            'natural_class_counts' => $actual,
            'directional_opportunities' => $actual['buy'] + $actual['sell'],
            'by_action' => $holdout['by_action'] ?? [],
            'classification_errors' => $holdout['classification_errors'] ?? [],
            // The Phase 2 baseline is calculated from finalized holdout prevalence.
            // It is not a training prior and must not be presented as one.
            'prediction_mix_baseline' => $baseline ?? 0.0,
            'baseline_source' => 'observed_finalized_action_holdout',
            'current_holdout_eligible' => (bool) ($holdout['eligible'] ?? false),
            'evidence_gates' => $evidence,
            'quality_gates' => $quality,
            'failed_gates' => $holdout['failed_gates'] ?? [],
        ];
    }

    public static function passes(array $analysis, int $minimumDirectional, float $minimumPrecision,
        float $baselineLift, float $wilsonFloor): bool
    {
        // Vary ONLY the two proposed sample/precision gates and the Wilson floor.
        // Historical opportunity/class-diversity and contradiction gates must still pass.
        $evidence = $analysis['evidence_gates'];
        unset($evidence['directional_predictions']);

        return ! in_array(false, $evidence, true)
            && ($analysis['quality_gates']['contradiction_rate'] ?? false) === true
            && $analysis['directional'] >= $minimumDirectional
            && $analysis['semantic_precision'] >= $minimumPrecision
            && $analysis['wilson_95_lower'] >= max(
                $wilsonFloor, $analysis['prediction_mix_baseline'] + $baselineLift
            );
    }
}
