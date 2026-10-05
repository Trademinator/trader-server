<?php

namespace App\Domain\Intelligence;

final class TrainingRequirements
{
    /** Minimum for contiguous, complete rows after any pattern-prefix exclusion; quality must still pass. */
    public static function minimumRows(array $settings, int $horizon, bool $fullFold = false): int
    {
        $validation = max(1, (int) $settings['min_validation_rows']);
        $tuning = $fullFold ? max($validation, (int) $settings['test_size']) : $validation;
        $training = ($settings['min_train_size'] ?? $settings['train_size']) + max($settings['gap'], $horizon) + $tuning;
        // Both the outer holdout and the inner walk-forward split purge unobserved labels.
        $rows = (int) ceil(($training + $horizon) / 0.8);
        while (floor($rows * 0.8) - $horizon < $training || $rows - floor($rows * 0.8) < $validation) {
            $rows++;
        }

        return $rows;
    }

    /** @return list<array{label: string, value: float, target: float, maximum: bool, percent: bool, passed: bool}> */
    public static function gates(array $metrics, array $settings): array
    {
        $gates = [];
        foreach ([
            ['Evaluated rows', 'evaluated', 'min_validation_rows', false, false],
            ['Directional predictions', 'directional', 'min_directional_predictions', false, false],
            ['Semantic precision', 'semantic_precision', 'min_semantic_precision', false, true],
            ['Directional coverage', 'coverage', 'min_coverage', false, true],
            ['Top/bottom contradictions', 'contradiction_rate', 'max_contradiction_rate', true, true],
        ] as [$label, $key, $setting, $maximum, $percent]) {
            $value = (float) ($metrics[$key] ?? 0);
            $target = (float) $settings[$setting];
            $gates[] = compact('label', 'value', 'target', 'maximum', 'percent') + [
                'passed' => $maximum ? $value <= $target : $value >= $target,
            ];
        }

        return $gates;
    }
}
