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

    /** The Action readiness UI must show the *actual* evidence/quality gates. */
    public static function gates(array $metrics, array $settings): array
    {
        if (! isset($metrics['validation_status'])) {
            // Older model reports remain inspectable, but cannot acquire v6 readiness.
            return [];
        }
        $rows = [
            ['validation_rows', 'Evaluated historical rows', $metrics['evaluated'], $settings['min_validation_rows'], false, false],
            ['directional_opportunities', 'Historical BUY/SELL opportunities', $metrics['directional_opportunities'],
                $settings['min_directional_opportunities'] ?? $settings['min_directional_predictions'], false, false],
            ['directional_predictions', 'Supported BUY/SELL predictions', $metrics['directional'], $settings['min_directional_predictions'], false, false],
            ['historical_class_diversity', 'At least two historical classes',
                count(array_filter($metrics['natural_class_counts'])), 2, false, false],
            ['semantic_precision', 'Directional precision', $metrics['semantic_precision'], $settings['min_semantic_precision'], false, true],
            ['directional_wilson_lower', 'Directional Wilson 95% lower bound',
                $metrics['directional_wilson_95']['lower'] ?? 0.0,
                $metrics['required_directional_wilson_lower'], false, true],
            ['contradiction_rate', 'Opposite-pivot disagreement', $metrics['contradiction_rate'], $settings['max_contradiction_rate'], true, true],
        ];

        return array_map(static fn (array $row): array => [
            'label' => $row[1], 'value' => (float) $row[2], 'target' => (float) $row[3],
            'maximum' => $row[4], 'percent' => $row[5],
            'passed' => (bool) ($metrics['gates'][$row[0]] ?? false),
        ], $rows);
    }
}
