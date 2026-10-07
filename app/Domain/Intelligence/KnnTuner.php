<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\WalkForward;
use RuntimeException;

final class KnnTuner
{
    public function __construct(private WeightedKnn $knn) {}

    public function tune(array $rows, array $settings, float $deadline): array
    {
        $rows = $this->knn->prepareRows($rows);

        return $this->tunePrepared($rows, $settings, $deadline);
    }

    public function tunePrepared(array $rows, array $settings, float $deadline): array
    {
        $trainSize = $settings['min_train_size'];
        $minimum = max(1, (int) floor($this->knn->minEffective) + 1);
        $maximum = min($settings['k_cap'], $trainSize, count($rows));
        if ($maximum < $minimum) {
            return ['k' => null, 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => [], 'candidates' => [],
                'selection' => 'semantic_precision_then_confidence_coverage_stability',
                'reason' => 'no_k_can_satisfy_effective_neighbor_floor'];
        }
        $initialCandidates = array_values(array_unique(array_filter([5, 9, 17, 33, 65, $maximum],
            fn (int $k): bool => $k >= $minimum && $k <= $maximum)));
        sort($initialCandidates);

        // Score every feasible K incrementally while each neighbor list is hot.
        // The first walk-forward fold has exactly min_train_size rows, so K cannot
        // exceed that size even when later expanding folds contain much more history.
        $accumulators = [];
        foreach (range($minimum, $maximum) as $k) {
            $accumulators[$k] = $this->newAccumulator($k);
        }

        $folds = [];
        foreach ((new WalkForward)->folds($rows, $trainSize, $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = array_map(fn (int $i): array => $rows[$i], $fold['train']);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
                'test_from_ms' => $rows[$fold['test'][0]]['decision_at_ms']];
            foreach ($fold['test'] as $index) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('K tuning time budget exceeded; reduce INTELLIGENCE_MAX_MODEL_AGE_DAYS or increase the build time budget.');
                }
                $row = $rows[$index];
                $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $maximum,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                foreach ($accumulators as $k => &$accumulator) {
                    $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), $fold['fold']);
                }
                unset($accumulator);
            }
            unset($training);
        }

        $allReports = [];
        foreach ($accumulators as $k => $accumulator) {
            $allReports[$k] = $this->finishAccumulator($accumulator, $settings);
        }
        $initialReports = array_intersect_key($allReports, array_flip($initialCandidates));
        $best = $this->best($initialReports);
        $center = $best ?? $maximum;
        $selected = array_values(array_unique([...$initialCandidates,
            ...range(max($minimum, $center - 3), min($maximum, $center + 3))]));
        sort($selected);
        $reports = array_intersect_key($allReports, array_flip($selected));
        ksort($reports);

        return ['k' => $this->best($reports), 'k_min' => $minimum, 'k_max' => $maximum, 'folds' => $folds,
            'candidates' => array_values($reports), 'selection' => 'semantic_precision_then_confidence_coverage_stability'];
    }

    public function evaluateWalkForwardPrepared(array $rows, array $settings, int $k, float $deadline): array
    {
        $accumulator = $this->newAccumulator($k);
        $folds = [];
        foreach ((new WalkForward)->folds($rows, $settings['min_train_size'], $settings['test_size'], $settings['gap'], true, null) as $fold) {
            $training = array_map(fn (int $i): array => $rows[$i], $fold['train']);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
                'test_from_ms' => $rows[$fold['test'][0]]['decision_at_ms']];
            foreach ($fold['test'] as $index) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('K tuning time budget exceeded; reduce INTELLIGENCE_MAX_MODEL_AGE_DAYS or increase the build time budget.');
                }
                $row = $rows[$index];
                $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $k,
                    $row['decision_at_ms'], $row['feature_weights'] ?? []);
                $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), $fold['fold']);
            }
            unset($training);
        }

        return ['report' => $this->finishAccumulator($accumulator, $settings), 'folds' => $folds];
    }

    public function evaluate(array $training, array $test, int $k, array $settings, float $deadline): array
    {
        $training = $this->knn->prepareRows($training);
        $test = $this->knn->prepareRows($test);

        return $this->evaluatePrepared($training, $test, $k, $settings, $deadline);
    }

    public function evaluatePrepared(array $training, array $test, int $k, array $settings, float $deadline): array
    {
        $accumulator = $this->newAccumulator($k);
        foreach ($test as $row) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('KNN evaluation time budget exceeded.');
            }
            $neighbors = $this->knn->neighborsPrepared($training, $row['vector'], $k,
                $row['decision_at_ms'], $row['feature_weights'] ?? []);
            $this->accumulate($accumulator, $row, $this->knn->vote($neighbors, $k), 1);
        }

        return $this->finishAccumulator($accumulator, $settings);
    }

    private function newAccumulator(int $k): array
    {
        return ['k' => $k, 'evaluated' => 0, 'directional' => 0, 'correct' => 0,
            'contradictions' => 0, 'confidence' => 0.0, 'folds' => [],
            'confusion' => array_fill_keys(['buy', 'hodl', 'sell'], array_fill_keys(['buy', 'hodl', 'sell'], 0))];
    }

    private function accumulate(array &$state, array $row, array $result, int $fold): void
    {
        $action = $result['action'];
        $state['evaluated']++;
        $state['confusion'][$row['label']][$action]++;
        $state['folds'][$fold] ??= ['correct' => 0, 'directional' => 0];
        if ($action === 'hodl') {
            return;
        }
        $state['directional']++;
        $state['confidence'] += $result['confidence'];
        $matches = $action === $row['label'];
        $state['correct'] += (int) $matches;
        $state['folds'][$fold]['directional']++;
        $state['folds'][$fold]['correct'] += (int) $matches;
        $top = (bool) ($row['semantic_top'] ?? $row['semantic']['top'] ?? false);
        $bottom = (bool) ($row['semantic_bottom'] ?? $row['semantic']['bottom'] ?? false);
        $state['contradictions'] += (int) (($action === 'buy' && $top) || ($action === 'sell' && $bottom));
    }

    private function finishAccumulator(array $state, array $settings): array
    {
        $directional = $state['directional'];
        $precision = $directional ? $state['correct'] / $directional : 0;
        $coverage = $state['evaluated'] ? $directional / $state['evaluated'] : 0;
        $contradictionRate = $directional ? $state['contradictions'] / $directional : 0;
        $rates = array_map(fn (array $fold): float => $fold['directional'] ? $fold['correct'] / $fold['directional'] : 0, $state['folds']);
        $stability = $rates ? 1 - (max($rates) - min($rates)) : 0;

        $gates = [
            'validation_rows' => $state['evaluated'] >= $settings['min_validation_rows'],
            'directional_predictions' => $directional >= $settings['min_directional_predictions'],
            'semantic_precision' => $precision >= $settings['min_semantic_precision'],
            'coverage' => $coverage >= $settings['min_coverage'],
            'contradiction_rate' => $contradictionRate <= $settings['max_contradiction_rate'],
        ];

        return ['k' => $state['k'], 'evaluated' => $state['evaluated'], 'directional' => $directional,
            'semantic_precision' => $precision, 'contradiction_rate' => $contradictionRate,
            'coverage' => $coverage, 'mean_confidence' => $directional ? $state['confidence'] / $directional : 0,
            'stability' => $stability, 'confusion' => $state['confusion'], 'gates' => $gates,
            'failed_gates' => array_keys(array_filter($gates, fn (bool $passed): bool => ! $passed)),
            'eligible' => ! in_array(false, $gates, true)];
    }

    private function score(array $cases, int $k, array $settings): array
    {
        $correct = $directional = $contradictions = $confidence = 0;
        $folds = [];
        $matrix = array_fill_keys(['buy', 'hodl', 'sell'], array_fill_keys(['buy', 'hodl', 'sell'], 0));
        foreach ($cases as $case) {
            $result = $case['result'] ?? $this->knn->vote($case['neighbors'], $k);
            $row = $case['row'];
            $action = $result['action'];
            $matrix[$row['label']][$action]++;
            $folds[$case['fold']] ??= ['correct' => 0, 'directional' => 0];
            if ($action === 'hodl') {
                continue;
            }
            $directional++;
            $confidence += $result['confidence'];
            $matches = $action === $row['label'];
            $correct += (int) $matches;
            $folds[$case['fold']]['directional']++;
            $folds[$case['fold']]['correct'] += (int) $matches;
            $top = (bool) ($row['semantic_top'] ?? $row['semantic']['top'] ?? false);
            $bottom = (bool) ($row['semantic_bottom'] ?? $row['semantic']['bottom'] ?? false);
            $contradictions += (int) (($action === 'buy' && $top) || ($action === 'sell' && $bottom));
        }
        $precision = $directional ? $correct / $directional : 0;
        $coverage = count($cases) ? $directional / count($cases) : 0;
        $contradictionRate = $directional ? $contradictions / $directional : 0;
        $rates = array_map(fn (array $fold): float => $fold['directional'] ? $fold['correct'] / $fold['directional'] : 0, $folds);
        $stability = $rates ? 1 - (max($rates) - min($rates)) : 0;

        $gates = [
            'validation_rows' => count($cases) >= $settings['min_validation_rows'],
            'directional_predictions' => $directional >= $settings['min_directional_predictions'],
            'semantic_precision' => $precision >= $settings['min_semantic_precision'],
            'coverage' => $coverage >= $settings['min_coverage'],
            'contradiction_rate' => $contradictionRate <= $settings['max_contradiction_rate'],
        ];

        return ['k' => $k, 'evaluated' => count($cases), 'directional' => $directional,
            'semantic_precision' => $precision, 'contradiction_rate' => $contradictionRate,
            'coverage' => $coverage, 'mean_confidence' => $directional ? $confidence / $directional : 0,
            'stability' => $stability, 'confusion' => $matrix, 'gates' => $gates,
            'failed_gates' => array_keys(array_filter($gates, fn (bool $passed): bool => ! $passed)),
            'eligible' => ! in_array(false, $gates, true)];
    }

    private function best(array $reports): ?int
    {
        $eligible = array_values(array_filter($reports, fn (array $report): bool => $report['eligible']));
        usort($eligible, fn (array $a, array $b): int => [
            $b['semantic_precision'], $b['mean_confidence'], $b['coverage'], $b['stability'], -$b['k'],
        ] <=> [
            $a['semantic_precision'], $a['mean_confidence'], $a['coverage'], $a['stability'], -$a['k'],
        ]);

        return $eligible[0]['k'] ?? null;
    }

    /** Score an auxiliary classifier against the same objective targets and gates. */
    public function evaluatePredictions(array $rows, array $predictions, array $settings): array
    {
        $cases = [];
        foreach ($rows as $i => $row) {
            $cases[] = ['row' => $row, 'fold' => 1, 'result' => $predictions[$i]];
        }

        return $this->score($cases, 0, $settings);
    }
}
