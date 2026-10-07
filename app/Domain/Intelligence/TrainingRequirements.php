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
    public static function outcomeGates(array $metrics, array $settings, array $outcome): array
    {
        $minimumSupported = max(5, (int) ($outcome['min_supported_predictions'] ?? 25));
        $minimumMacroF1 = (float) ($outcome['min_macro_f1'] ?? 0.20);
        $minimumImprovement = (float) ($outcome['min_baseline_improvement'] ?? 0.02);
        $minimumCoverage = (float) ($outcome['min_coverage'] ?? 0.01);
        $baselineImprovement = (float) ($metrics['baseline']['improvement'] ?? 0.0);

        $rows = [
            ['Evaluated rows', (float) ($metrics['evaluated'] ?? 0), (float) $settings['min_validation_rows'], false],
            ['Supported Outcome predictions', (float) ($metrics['supported'] ?? 0), (float) $minimumSupported, false],
            ['Supported Outcome Macro-F1', (float) ($metrics['supported_macro_f1'] ?? $metrics['macro_f1'] ?? 0), $minimumMacroF1, true],
            ['Improvement over Outcome baseline', $baselineImprovement, $minimumImprovement, true],
            ['Outcome coverage', (float) ($metrics['coverage'] ?? 0), $minimumCoverage, true],
        ];

        return array_map(fn (array $row): array => [
            'label' => $row[0],
            'value' => $row[1],
            'target' => $row[2],
            'maximum' => false,
            'percent' => $row[3],
            'passed' => $row[1] >= $row[2],
        ], $rows);
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
