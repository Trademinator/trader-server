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
        $trainSize = $settings['train_size'];
        $maximum = min($settings['k_cap'], (int) floor(sqrt($trainSize)));
        $candidates = array_values(array_unique(array_filter([1, 3, 5, 9, 17, 33, 65, $maximum],
            fn (int $k): bool => $k > 0 && $k <= $maximum)));
        sort($candidates);
        $cases = $folds = [];
        foreach ((new WalkForward)->folds($rows, $trainSize, $settings['test_size'], $settings['gap'], false) as $fold) {
            $training = array_map(fn (int $i): array => $rows[$i], $fold['train']);
            $folds[] = ['fold' => $fold['fold'], 'train_rows' => count($training),
                'labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
                'test_from_ms' => $rows[$fold['test'][0]]['decision_at_ms']];
            foreach ($fold['test'] as $index) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('K tuning time budget exceeded; reduce intelligence.max_rows or train_size.');
                }
                $row = $rows[$index];
                $cases[] = ['row' => $row, 'fold' => $fold['fold'],
                    'neighbors' => $this->knn->neighborsPrepared($training, $row['vector'], $maximum, $row['decision_at_ms'], $row['feature_weights'] ?? [])];
            }
        }
        $reports = [];
        foreach ($candidates as $k) {
            $reports[$k] = $this->score($cases, $k, $settings);
        }
        $best = $this->best($reports);
        $center = $best ?? $maximum;
        foreach (range(max(1, $center - 3), min($maximum, $center + 3)) as $k) {
            $reports[$k] ??= $this->score($cases, $k, $settings);
        }
        ksort($reports);

        return ['k' => $this->best($reports), 'k_max' => $maximum, 'folds' => $folds,
            'candidates' => array_values($reports), 'selection' => 'semantic_precision_then_confidence_coverage_stability'];
    }

    public function evaluate(array $training, array $test, int $k, array $settings, float $deadline): array
    {
        $training = $this->knn->prepareRows($training);
        $test = $this->knn->prepareRows($test);
        $cases = [];
        foreach ($test as $row) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('KNN evaluation time budget exceeded.');
            }
            $cases[] = ['row' => $row, 'fold' => 1,
                'neighbors' => $this->knn->neighborsPrepared($training, $row['vector'], $k, $row['decision_at_ms'], $row['feature_weights'] ?? [])];
        }

        return $this->score($cases, $k, $settings);
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
            $semantic = $row['semantic'];
            $contradictions += (int) (($action === 'buy' && $semantic['top']) || ($action === 'sell' && $semantic['bottom']));
        }
        $precision = $directional ? $correct / $directional : 0;
        $coverage = count($cases) ? $directional / count($cases) : 0;
        $contradictionRate = $directional ? $contradictions / $directional : 0;
        $rates = array_map(fn (array $fold): float => $fold['directional'] ? $fold['correct'] / $fold['directional'] : 0, $folds);
        $stability = $rates ? 1 - (max($rates) - min($rates)) : 0;

        return ['k' => $k, 'evaluated' => count($cases), 'directional' => $directional,
            'semantic_precision' => $precision, 'contradiction_rate' => $contradictionRate,
            'coverage' => $coverage, 'mean_confidence' => $directional ? $confidence / $directional : 0,
            'stability' => $stability, 'confusion' => $matrix,
            'eligible' => count($cases) >= $settings['min_validation_rows']
                && $directional >= $settings['min_directional_predictions']
                && $precision >= $settings['min_semantic_precision']
                && $coverage >= $settings['min_coverage']
                && $contradictionRate <= $settings['max_contradiction_rate']];
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
