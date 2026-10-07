<?php

namespace App\Domain\Intelligence;

final class AutomaticPatternAblation
{
    public const VERSION = 'automatic-pattern-ablation-v1';

    public function comparisonCandidate(array $selection): ?array
    {
        $selected = $this->selectedCandidate($selection);
        if ($selected !== null) {
            return $selected;
        }

        return $this->bestObserved($selection['candidates'] ?? []);
    }

    public function chooseAtK(array $technical, array $stacked, array $patternKeys, int $commonRows): array
    {
        $stackedEligible = ($stacked['eligible'] ?? false) === true;
        $technicalEligible = ($technical['eligible'] ?? false) === true;
        $usePatterns = $stackedEligible
            && (! $technicalEligible || $this->objective($stacked) > $this->objective($technical));

        return [
            'version' => self::VERSION,
            'selection_basis' => 'same_k_same_walk_forward_rows_final_holdout_untouched',
            'comparison_k' => $technical['k'] ?? $stacked['k'] ?? null,
            'selected' => $usePatterns ? 'technical_plus_patterns' : 'technical_only',
            'reason' => $usePatterns
                ? ($technicalEligible ? 'patterns_improve_automatic_selection' : 'patterns_create_eligible_automatic_model')
                : ($stackedEligible ? 'technical_equal_or_better_on_automatic_selection' : 'patterns_not_validated_for_automatic_target'),
            'candidate_pattern_keys' => $patternKeys,
            'common_rows' => $commonRows,
            'technical' => $this->summary($technical),
            'technical_plus_patterns' => $this->summary($stacked),
        ];
    }

    private function selectedCandidate(array $selection): ?array
    {
        $k = $selection['k'] ?? null;
        if (! is_int($k)) {
            return null;
        }

        foreach ($selection['candidates'] ?? [] as $candidate) {
            if (($candidate['k'] ?? null) === $k && ($candidate['eligible'] ?? false) === true) {
                return $candidate;
            }
        }

        return null;
    }

    private function bestObserved(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn (array $a, array $b): int => $this->objective($b) <=> $this->objective($a));

        return $candidates[0];
    }

    private function summary(array $candidate): array
    {
        return array_intersect_key($candidate, array_flip([
            'k', 'evaluated', 'directional', 'semantic_precision', 'contradiction_rate',
            'coverage', 'mean_confidence', 'stability', 'eligible', 'failed_gates',
        ]));
    }

    private function objective(array $candidate): array
    {
        return [
            (float) ($candidate['semantic_precision'] ?? 0),
            (float) ($candidate['mean_confidence'] ?? 0),
            (float) ($candidate['coverage'] ?? 0),
            (float) ($candidate['stability'] ?? 0),
            -(int) ($candidate['k'] ?? PHP_INT_MAX),
        ];
    }
}
