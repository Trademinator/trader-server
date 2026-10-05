<?php

namespace App\Domain\Intelligence;

use RuntimeException;

final class ValidationGateCalibration
{
    private const WILSON_Z_95 = 1.959963984540054;

    /**
     * Analyze one persisted Server model without changing its readiness.
     *
     * @param  list<array<string, mixed>>  $sourceRows
     * @return array<string, mixed>
     */
    public function analyze(array $artifact, array $sourceRows): array
    {
        $holdout = $artifact['holdout'] ?? null;
        if (! is_array($holdout) || ! is_array($holdout['confusion'] ?? null)) {
            throw new RuntimeException('Model has no final holdout confusion matrix to calibrate.');
        }

        $confusion = $this->confusion($holdout['confusion']);
        $evaluated = array_sum(array_map('array_sum', $confusion));
        $predictedBuy = array_sum(array_column($confusion, 'buy'));
        $predictedSell = array_sum(array_column($confusion, 'sell'));
        $directional = $predictedBuy + $predictedSell;
        $correct = $confusion['buy']['buy'] + $confusion['sell']['sell'];
        $precision = $directional > 0 ? $correct / $directional : 0.0;
        $coverage = $evaluated > 0 ? $directional / $evaluated : 0.0;

        if ((int) ($holdout['evaluated'] ?? -1) !== $evaluated
            || (int) ($holdout['directional'] ?? -1) !== $directional
            || abs((float) ($holdout['semantic_precision'] ?? -1) - $precision) > 1e-9
            || abs((float) ($holdout['coverage'] ?? -1) - $coverage) > 1e-9) {
            throw new RuntimeException('Stored holdout metrics do not match the persisted confusion matrix.');
        }

        [$training, $availability] = $this->reconstructTraining($artifact, $sourceRows);
        $expectedTraining = $artifact['training_data']['tuning_rows'] ?? null;
        if (! is_int($expectedTraining) || $expectedTraining !== count($training)) {
            throw new RuntimeException('Could not reproduce the model pre-holdout training row count.');
        }

        $trainSize = (int) ($artifact['settings']['min_train_size'] ?? $artifact['settings']['train_size'] ?? 0);
        if ($trainSize < 1) {
            throw new RuntimeException('Model has an invalid training-window size.');
        }
        $evaluationTraining = isset($artifact['settings']['min_train_size']) ? $training : array_slice($training, -$trainSize);
        if ($evaluationTraining === []) {
            throw new RuntimeException('Model has no pre-holdout rows for baseline calibration.');
        }

        $classCounts = ['buy' => 0, 'hodl' => 0, 'sell' => 0];
        foreach ($evaluationTraining as $row) {
            $label = $row['label'] ?? null;
            if (! is_string($label) || ! array_key_exists($label, $classCounts)) {
                throw new RuntimeException('Source dataset contains an invalid semantic label.');
            }
            $classCounts[$label]++;
        }

        $trainingCount = count($evaluationTraining);
        $buyRate = $classCounts['buy'] / $trainingCount;
        $sellRate = $classCounts['sell'] / $trainingCount;
        $predictionMixBaseline = $directional > 0
            ? ($predictedBuy / $directional) * $buyRate + ($predictedSell / $directional) * $sellRate
            : 0.0;

        $settings = $artifact['settings'] ?? [];
        foreach (['min_validation_rows', 'min_coverage', 'max_contradiction_rate'] as $key) {
            if (! is_numeric($settings[$key] ?? null)) {
                throw new RuntimeException('Model is missing the existing '.$key.' validation gate.');
            }
        }

        return [
            'model_id' => $artifact['model_id'] ?? null,
            'dataset_id' => $artifact['dataset_id'] ?? null,
            'exchange' => $artifact['exchange'] ?? null,
            'symbol' => $artifact['symbol'] ?? null,
            'period' => $artifact['period'] ?? null,
            'status' => $artifact['automatic']['status'] ?? $artifact['status'] ?? null,
            'reason' => $artifact['automatic']['reason'] ?? $artifact['reason'] ?? null,
            'scoring_component' => isset($artifact['ensemble']) ? 'automatic' : 'legacy_model',
            'validation_version' => $artifact['validation_version'] ?? null,
            'holdout_from_ms' => $artifact['holdout_from_ms'] ?? null,
            'evaluated' => $evaluated,
            'directional' => $directional,
            'correct_directional' => $correct,
            'semantic_precision' => $precision,
            'wilson_95_lower' => $this->wilsonLower($correct, $directional),
            'coverage' => $coverage,
            'contradiction_rate' => (float) ($holdout['contradiction_rate'] ?? 0.0),
            'predicted_buy' => $predictedBuy,
            'predicted_sell' => $predictedSell,
            'training_rows' => count($training),
            'evaluation_training_rows' => $trainingCount,
            'training_class_counts' => $classCounts,
            'training_buy_rate' => $buyRate,
            'training_sell_rate' => $sellRate,
            'prediction_mix_baseline' => $predictionMixBaseline,
            'directional_majority_baseline' => max($buyRate, $sellRate),
            'current_holdout_eligible' => (bool) ($holdout['eligible'] ?? false),
            'availability_cutoffs' => $availability,
            'existing_gates' => [
                'min_validation_rows' => (int) $settings['min_validation_rows'],
                'min_coverage' => (float) $settings['min_coverage'],
                'max_contradiction_rate' => (float) $settings['max_contradiction_rate'],
            ],
        ];
    }

    public function passes(
        array $analysis,
        int $minDirectional,
        float $minPrecision,
        float $baselineLift,
        float $wilsonFloor
    ): bool {
        $gates = $analysis['existing_gates'];

        return $analysis['evaluated'] >= $gates['min_validation_rows']
            && $analysis['directional'] >= $minDirectional
            && $analysis['semantic_precision'] >= $minPrecision
            && $analysis['coverage'] >= $gates['min_coverage']
            && $analysis['contradiction_rate'] <= $gates['max_contradiction_rate']
            && $analysis['wilson_95_lower'] >= max(
                $wilsonFloor,
                $analysis['prediction_mix_baseline'] + $baselineLift
            );
    }

    public function wilsonLower(int $correct, int $total): float
    {
        if ($total < 1 || $correct < 0 || $correct > $total) {
            return 0.0;
        }

        $z2 = self::WILSON_Z_95 ** 2;
        $p = $correct / $total;
        $denominator = 1 + $z2 / $total;
        $center = $p + $z2 / (2 * $total);
        $margin = self::WILSON_Z_95 * sqrt(
            $p * (1 - $p) / $total + $z2 / (4 * $total ** 2)
        );

        return max(0.0, ($center - $margin) / $denominator);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @return array{0: list<array<string, mixed>>, 1: array<string, ?int>}
     */
    private function reconstructTraining(array $artifact, array $sourceRows): array
    {
        $holdoutFrom = $artifact['holdout_from_ms'] ?? null;
        if (! is_int($holdoutFrom) || $holdoutFrom < 1) {
            throw new RuntimeException('Model has no valid final holdout cutoff.');
        }

        $patternAfter = null;
        if (($artifact['pattern_keys'] ?? []) !== []) {
            $models = $artifact['patterns']['models'] ?? null;
            if (! is_array($models) || $models === []) {
                throw new RuntimeException('Pattern-stacked model is missing persisted pattern availability.');
            }
            $available = [];
            foreach ($models as $model) {
                if (! is_int($model['available_at_ms'] ?? null)) {
                    throw new RuntimeException('Pattern model availability is invalid.');
                }
                $available[] = $model['available_at_ms'];
            }
            $patternAfter = max($available);
        }

        $leadLagAfter = null;
        if (($artifact['lead_lag_keys'] ?? []) !== []) {
            $leadLagAfter = $artifact['lead_lag']['available_at_ms'] ?? null;
            if (! is_int($leadLagAfter)) {
                throw new RuntimeException('Lead/lag model availability is invalid.');
            }
        }

        $humanFrom = null;
        if (($artifact['human_guidance']['influence'] ?? false) === true) {
            $humanFrom = $artifact['human_guidance']['downstream_from_ms'] ?? null;
            if (! is_int($humanFrom)) {
                throw new RuntimeException('Human-guidance downstream cutoff is invalid.');
            }
        }

        $candleFrom = null;
        if (($artifact['candle_guidance']['influence'] ?? false) === true
            && ($artifact['candle_guidance']['mode'] ?? null) !== 'independent_knn') {
            $candleFrom = $artifact['candle_guidance']['downstream_from_ms'] ?? null;
            if (! is_int($candleFrom)) {
                throw new RuntimeException('Candle-guidance downstream cutoff is invalid.');
            }
        }

        $windowFrom = $artifact['training_data']['window']['from_ms'] ?? null;
        $training = [];
        foreach ($sourceRows as $row) {
            $decision = $row['decision_at_ms'] ?? null;
            $available = $row['label_available_at_ms'] ?? null;
            if (! is_int($decision) || ! is_int($available)) {
                throw new RuntimeException('Source dataset row has invalid chronology.');
            }
            if ($decision >= $holdoutFrom || $available >= $holdoutFrom
                || ($windowFrom !== null && $decision < $windowFrom)
                || ($patternAfter !== null && $decision <= $patternAfter)
                || ($leadLagAfter !== null && $decision <= $leadLagAfter)
                || ($humanFrom !== null && $decision < $humanFrom)
                || ($candleFrom !== null && $decision < $candleFrom)) {
                continue;
            }
            $training[] = $row;
        }

        return [$training, [
            'pattern_after_ms' => $patternAfter,
            'lead_lag_after_ms' => $leadLagAfter,
            'human_from_ms' => $humanFrom,
            'candle_from_ms' => $candleFrom,
        ]];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function confusion(array $matrix): array
    {
        $labels = ['buy', 'hodl', 'sell'];
        $normalized = [];
        foreach ($labels as $actual) {
            foreach ($labels as $predicted) {
                $value = $matrix[$actual][$predicted] ?? null;
                if (! is_int($value) || $value < 0) {
                    throw new RuntimeException('Holdout confusion matrix is invalid.');
                }
                $normalized[$actual][$predicted] = $value;
            }
        }

        return $normalized;
    }
}
