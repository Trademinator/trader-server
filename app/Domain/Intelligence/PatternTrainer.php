<?php

namespace App\Domain\Intelligence;

use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use RuntimeException;

final class PatternTrainer
{
    public function __construct(private PatternCatalog $catalog, private ProbabilityCalibration $calibration) {}

    public function train(array $rows, array $settings, float $deadline): array
    {
        $models = $reports = [];
        foreach (PatternCatalog::TYPES as $type) {
            $samples = [];
            foreach ($rows as $row) {
                foreach ($row['patterns'] as $candidate) {
                    if ($candidate['type'] === $type) {
                        $samples[] = ['vector' => $this->catalog->vector($row['vector'], $candidate),
                            'label' => $candidate['label'], 'decision_at_ms' => $row['decision_at_ms'],
                            'label_available_at_ms' => $candidate['label_available_at_ms']];
                    }
                }
            }
            $count = count($samples);
            if ($count < $settings['min_samples']) {
                $reports[$type] = ['status' => 'insufficient_samples', 'samples' => $count];

                continue;
            }
            $calibrationStart = (int) floor($count * 0.6);
            $testStart = (int) floor($count * 0.8);
            $train = array_values(array_filter(array_slice($samples, 0, $calibrationStart),
                fn (array $row): bool => $row['label_available_at_ms'] < $samples[$calibrationStart]['decision_at_ms']));
            $calibrate = array_values(array_filter(array_slice($samples, $calibrationStart, $testStart - $calibrationStart),
                fn (array $row): bool => $row['label_available_at_ms'] < $samples[$testStart]['decision_at_ms']));
            $test = array_slice($samples, $testStart);
            if (min(count($train), count($calibrate), count($test)) < $settings['min_block_rows']
                || count(array_unique(array_column($train, 'label'))) < 2
                || count(array_unique(array_column($calibrate, 'label'))) < 2) {
                $reports[$type] = ['status' => 'insufficient_chronological_classes', 'samples' => $count];

                continue;
            }
            $learners = [
                'random_forest' => new RandomForest(new ClassificationTree(10, 3), $settings['trees'], 0.8),
                'weighted_knn' => new KNearestNeighbors(min($settings['k'], count($train)), true),
            ];
            $baseRate = count(array_filter($train, fn (array $row): bool => $row['label'] === 'completed')) / count($train);
            $baseline = $this->calibration->metrics(array_fill(0, count($test), $baseRate), array_column($test, 'label'));
            $candidates = [];
            foreach ($learners as $name => $learner) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Pattern training time budget exceeded.');
                }
                $learner->train(new Labeled(array_column($train, 'vector'), array_column($train, 'label')));
                $raw = array_map(fn (array $p): float => $p['completed'] ?? 0.0,
                    $learner->proba(new Unlabeled(array_column($calibrate, 'vector'))));
                $mapping = $this->calibration->fit($raw, array_column($calibrate, 'label'));
                $rawTest = array_map(fn (array $p): float => $p['completed'] ?? 0.0,
                    $learner->proba(new Unlabeled(array_column($test, 'vector'))));
                $calibrated = array_map(fn (float $p): float => $this->calibration->apply($p, $mapping), $rawTest);
                $candidates[$name] = ['algorithm' => $name, 'estimator' => $learner, 'calibration' => $mapping,
                    'metrics' => $this->calibration->metrics($calibrated, array_column($test, 'label')),
                    'raw_metrics' => $this->calibration->metrics($rawTest, array_column($test, 'label')),
                    'available_at_ms' => max(array_column($test, 'label_available_at_ms'))];
            }
            uasort($candidates, fn (array $a, array $b): int => [$a['metrics']['brier'], $a['metrics']['log_loss'], $a['metrics']['calibration_error']]
                <=> [$b['metrics']['brier'], $b['metrics']['log_loss'], $b['metrics']['calibration_error']]);
            $winner = reset($candidates);
            $eligible = $winner['metrics']['brier'] < $baseline['brier'];
            if ($eligible) {
                $models[$type] = $winner;
            }
            $reports[$type] = [
                'status' => $eligible ? 'validated' : 'no_improvement_over_prior',
                'selected' => $eligible ? $winner['algorithm'] : null,
                'samples' => $count, 'train_rows' => count($train), 'calibration_rows' => count($calibrate),
                'test_rows' => count($test),
                'train_labels_available_by_ms' => max(array_column($train, 'label_available_at_ms')),
                'calibration_from_ms' => $calibrate[0]['decision_at_ms'],
                'calibration_labels_available_by_ms' => max(array_column($calibrate, 'label_available_at_ms')),
                'test_from_ms' => $test[0]['decision_at_ms'], 'prior_baseline' => $baseline,
                'candidates' => array_map(function (array $candidate): array {
                    unset($candidate['estimator'], $candidate['calibration']);

                    return $candidate;
                }, $candidates),
            ];
        }

        return ['models' => $models, 'report' => $reports, 'version' => PatternCatalog::VERSION];
    }

    public function predict(array $bundle, array $vector, array $candidates, int $asOfMs): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $model = $bundle['models'][$candidate['type']] ?? null;
            $available = $model !== null && $model['available_at_ms'] < $asOfMs;
            $probability = null;
            if ($available) {
                $raw = $model['estimator']->proba(new Unlabeled([$this->catalog->vector($vector, $candidate)]))[0]['completed'] ?? 0.0;
                $probability = $this->calibration->apply($raw, $model['calibration']);
            }
            $result[] = [
                'type' => $candidate['type'], 'length' => $candidate['length'], 'stage' => $candidate['stage'],
                'progress' => $candidate['progress'], 'similarity' => $candidate['similarity'],
                'completion_probability' => $probability,
                'reason' => $available ? 'calibrated' : 'no_prior_validated_model',
                'algorithm' => $available ? $model['algorithm'] : null,
            ];
        }

        return $result;
    }

    public function featureKeys(array $bundle): array
    {
        $keys = [];
        foreach (array_keys($bundle['models']) as $type) {
            $keys[] = 'pattern.'.$type.'.probability';
            $keys[] = 'pattern.'.$type.'.present';
        }

        return $keys;
    }

    public function features(array $bundle, array $predictions): array
    {
        $byType = array_column($predictions, null, 'type');
        $vector = [];
        foreach (array_keys($bundle['models']) as $type) {
            $probability = $byType[$type]['completion_probability'] ?? null;
            $vector[] = $probability ?? 0.5;
            $vector[] = $probability === null ? 0.0 : 1.0;
        }

        return $vector;
    }
}
