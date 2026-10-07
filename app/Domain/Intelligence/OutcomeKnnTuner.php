<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\SemanticLabels;
use App\Domain\Research\WalkForward;
use RuntimeException;

final class OutcomeKnnTuner
{
    public function __construct(private WeightedKnn $neighbors, private OutcomeKnn $outcomes) {}

    public function tunePrepared(array $rows, array $settings, float $deadline): array
    {
        $trainSize = $settings['min_train_size'];
        $minimum = max(1, (int) floor($this->neighbors->minEffective) + 1);
        $maximum = min($settings['k_cap'], $trainSize, count($rows));
        if ($maximum < $minimum) {
            return ['k' => null, 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => [], 'candidates' => [],
                'selection' => 'macro_f1_then_accuracy_confidence_coverage',
                'reason' => 'no_k_can_satisfy_effective_neighbor_floor'];
        }
        $initialCandidates = array_values(array_unique(array_filter([5, 9, 17, 33, 65, $maximum],
            fn (int $k): bool => $k >= $minimum && $k <= $maximum)));
        sort($initialCandidates);

        $reports = [];
        foreach (range($minimum, $maximum) as $k) {
            $reports[$k] = $this->emptyReport($k);
        }

        $folds = [];
        foreach ((new WalkForward)->folds($rows, $trainSize, $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = array_map(fn (int $i): array => $rows[$i], $fold['train']);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
                'test_from_ms' => $rows[$fold['test'][0]]['decision_at_ms']];
            foreach ($fold['test'] as $index) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Outcome K tuning time budget exceeded.');
                }
                $row = $rows[$index];
                $neighbors = $this->neighbors->neighborsPrepared($training, $row['vector'], $maximum,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                foreach ($reports as $k => &$report) {
                    $this->accumulate($report, $row, $this->outcomes->vote($neighbors, $k));
                }
                unset($report);
            }
            unset($training);
        }

        foreach ($reports as &$report) {
            $report = $this->finish($report, $settings);
        }
        unset($report);
        $initialReports = array_intersect_key($reports, array_flip($initialCandidates));
        $best = $this->best($initialReports);
        $center = $best ?? $maximum;
        $selected = array_values(array_unique([...$initialCandidates,
            ...range(max($minimum, $center - 3), min($maximum, $center + 3))]));
        sort($selected);
        $reports = array_intersect_key($reports, array_flip($selected));
        ksort($reports);

        return ['k' => $this->best($reports), 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => $folds,
            'candidates' => array_values($reports),
            'selection' => 'macro_f1_then_accuracy_confidence_coverage'];
    }

    public function evaluateWalkForwardPrepared(array $rows, array $settings, int $k, float $deadline): array
    {
        $state = $this->emptyReport($k);
        $folds = [];
        foreach ((new WalkForward)->folds($rows, $settings['min_train_size'], $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = array_map(fn (int $i): array => $rows[$i], $fold['train']);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
                'test_from_ms' => $rows[$fold['test'][0]]['decision_at_ms']];
            foreach ($fold['test'] as $index) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Outcome K tuning time budget exceeded.');
                }
                $row = $rows[$index];
                $nearest = $this->neighbors->neighborsPrepared($training, $row['vector'], $k,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                $this->accumulate($state, $row, $this->outcomes->vote($nearest, $k));
            }
        }

        return ['report' => $this->finish($state, $settings), 'folds' => $folds];
    }

    public function evaluatePrepared(array $training, array $test, int $k, array $settings, float $deadline): array
    {
        $state = $this->emptyReport($k);
        foreach ($test as $row) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Outcome KNN evaluation time budget exceeded.');
            }
            $neighbors = $this->neighbors->neighborsPrepared($training, $row['vector'], $k,
                $row['decision_at_ms'], $row['feature_weights'] ?? []);
            $this->accumulate($state, $row, $this->outcomes->vote($neighbors, $k));
        }

        return $this->finish($state, $settings);
    }

    private function emptyReport(int $k): array
    {
        return ['k' => $k, 'evaluated' => 0, 'supported' => 0, 'correct' => 0, 'confidence' => 0.0,
            'confusion' => array_fill_keys(SemanticLabels::OUTCOMES,
                array_fill_keys(SemanticLabels::OUTCOMES, 0))];
    }

    private function accumulate(array &$state, array $row, array $prediction): void
    {
        $actual = $row['label'];
        $predicted = $prediction['outcome'];
        $state['evaluated']++;
        $state['confusion'][$actual][$predicted]++;
        if ($prediction['reason'] !== 'supported') {
            return;
        }
        $state['supported']++;
        $state['correct'] += (int) ($actual === $predicted);
        $state['confidence'] += $prediction['confidence'];
    }

    private function finish(array $state, array $settings): array
    {
        $coverage = $state['evaluated'] ? $state['supported'] / $state['evaluated'] : 0.0;
        $accuracy = $state['supported'] ? $state['correct'] / $state['supported'] : 0.0;
        $f1 = [];
        foreach (SemanticLabels::OUTCOMES as $class) {
            $tp = $state['confusion'][$class][$class];
            $predicted = array_sum(array_column($state['confusion'], $class));
            $actual = array_sum($state['confusion'][$class]);
            $precision = $predicted ? $tp / $predicted : 0.0;
            $recall = $actual ? $tp / $actual : 0.0;
            $f1[] = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
        }
        $macroF1 = array_sum($f1) / count($f1);
        $minimumSupported = max(5, (int) $settings['min_directional_predictions']);

        $gates = [
            'validation_rows' => $state['evaluated'] >= $settings['min_validation_rows'],
            'supported_predictions' => $state['supported'] >= $minimumSupported,
            'macro_f1' => $macroF1 >= $settings['min_semantic_precision'],
            'coverage' => $coverage >= $settings['min_coverage'],
        ];

        return [...$state, 'accuracy' => $accuracy, 'macro_f1' => $macroF1,
            'semantic_precision' => $macroF1, 'coverage' => $coverage,
            'mean_confidence' => $state['supported'] ? $state['confidence'] / $state['supported'] : 0.0,
            'stability' => 1.0, 'gates' => $gates,
            'failed_gates' => array_keys(array_filter($gates, fn (bool $passed): bool => ! $passed)),
            'eligible' => ! in_array(false, $gates, true)];
    }

    private function best(array $reports): ?int
    {
        $eligible = array_values(array_filter($reports, fn (array $report): bool => $report['eligible']));
        usort($eligible, fn (array $a, array $b): int => [
            $b['macro_f1'], $b['accuracy'], $b['mean_confidence'], $b['coverage'], -$b['k'],
        ] <=> [
            $a['macro_f1'], $a['accuracy'], $a['mean_confidence'], $a['coverage'], -$a['k'],
        ]);

        return $eligible[0]['k'] ?? null;
    }
}
