<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class IntelligenceTrainer
{
    public const VERSION = 'm5-two-knn-v2';

    public function __construct(
        private DatasetStore $datasets,
        private ModelStore $models,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private HumanCandleKnn $candleKnn,
        private AutomaticPatternAblation $patternAblation,
    ) {}

    public function train(string $dataset, ?float $deadline = null, ?string $generation = null, array $buildPerformance = [], array $schemaSelection = []): array
    {
        $buildPerformance['started_at'] ??= now()->toIso8601String();
        $buildPerformance['started_monotonic_ns'] ??= hrtime(true);
        $buildPerformance['stages'] ??= [];
        $datasetLoadStarted = hrtime(true);
        [$manifest, $datasetRows] = $this->datasets->open($dataset);
        $buildPerformance['stages']['dataset_ms'] = (int) ($buildPerformance['stages']['dataset_ms'] ?? 0)
            + $this->elapsedMs($datasetLoadStarted);
        if ($manifest['feature_version'] !== FeatureEngine::VERSION
            || $manifest['label_definition']['version'] !== SemanticLabels::VERSION) {
            throw new InvalidArgumentException('M4 requires current features and cost-free semantic labels; build fresh knowledge.');
        }
        if ($manifest['as_of_ms'] > now()->getTimestampMs()) {
            throw new InvalidArgumentException('Knowledge cutoff cannot be in the future.');
        }
        $window = KnowledgeWindow::metadata($manifest['as_of_ms']);
        $snapshotRows = count($datasetRows);
        $rows = [];
        $ageExcluded = 0;
        foreach ($datasetRows as $row) {
            if ($row['decision_at_ms'] < $window['from_ms']) {
                $ageExcluded++;

                continue;
            }
            if (! isset($row['semantic'], $row['patterns'])
                || $row['label_available_at_ms'] > $manifest['as_of_ms']
                || ! in_array($row['label'], ['buy', 'hodl', 'sell'], true)) {
                throw new InvalidArgumentException('Invalid semantic knowledge row.');
            }
            foreach ($row['patterns'] as $pattern) {
                if (! in_array($pattern['type'] ?? null, PatternCatalog::TYPES, true)
                    || ! in_array($pattern['label'] ?? null, ['completed', 'failed'], true)
                    || ! is_int($pattern['label_available_at_ms'] ?? null)
                    || $pattern['label_available_at_ms'] <= $row['decision_at_ms']
                    || $pattern['label_available_at_ms'] > $row['label_available_at_ms']) {
                    throw new InvalidArgumentException('Pattern outcome is outside the closed semantic horizon.');
                }
            }
            $rows[] = [
                'decision_at_ms' => $row['decision_at_ms'],
                'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => NormalizedVector::from($row['vector'], $manifest['keys']),
                'label' => $row['label'],
                'semantic_bottom' => (bool) $row['semantic']['bottom'],
                'semantic_top' => (bool) $row['semantic']['top'],
                'patterns' => $row['patterns'],
            ];
        }
        if ($rows === []) {
            throw new InvalidArgumentException('No eligible history within INTELLIGENCE_MAX_MODEL_AGE_DAYS.');
        }
        $rowAudit = ['input_rows' => count($rows), 'unique_rows' => count($rows), 'duplicates' => 0];
        $settings = config('intelligence.knn');
        $ensemble = KnnEnsemble::settings(config('intelligence.ensemble'));
        $deadline ??= microtime(true) + config('intelligence.max_seconds');
        $publicationDeadline = OptionalGuidance::publicationDeadline($deadline);
        $lock = Cache::lock('trademinator:intelligence:'.ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']), 1020);
        if (! $lock->get()) {
            throw new RuntimeException('Intelligence training is already running for this market and period.');
        }
        try {
            $patternSettings = config('intelligence.patterns');
            $sourceRows = count($rows);
            $patternBundle = ['models' => [], 'report' => [], 'version' => PatternCatalog::VERSION];
            $stageStarted = hrtime(true);
            if ($patternSettings['enabled']) {
                $patternRows = array_slice($rows, 0, (int) floor(count($rows) * 0.4));
                $patternBundle = $this->patterns->train($patternRows, $patternSettings, $deadline);
                unset($patternRows);
            }
            $buildPerformance['stages']['patterns_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $leadLag = $this->leadLag->prepare($manifest, $rows, $deadline);
            $buildPerformance['stages']['lead_lag_ms'] = $this->elapsedMs($stageStarted);
            $leadLagBundle = $leadLag['bundle'];
            unset($leadLag['bundle']);
            $candidatePatternKeys = $patternSettings['as_knn_features'] ? $this->patterns->featureKeys($patternBundle) : [];
            $patternKeys = [];
            $patternKnownAt = $candidatePatternKeys === [] ? null : max(array_column($patternBundle['models'], 'available_at_ms'));
            $patternAblation = [
                'version' => AutomaticPatternAblation::VERSION,
                'selection_basis' => 'same_k_same_walk_forward_rows_final_holdout_untouched',
                'selected' => 'technical_only',
                'reason' => $candidatePatternKeys === [] ? 'no_validated_pattern_features' : 'no_comparison_k',
                'candidate_pattern_keys' => $candidatePatternKeys,
                'common_rows' => 0,
            ];

            $baseRows = $rows;
            $stackedRows = null;
            if ($patternKnownAt !== null) {
                $stackedRows = [];
                foreach ($baseRows as &$row) {
                    if ($row['decision_at_ms'] > $patternKnownAt) {
                        $stacked = $row;
                        $predictions = $this->patterns->predict($patternBundle, $row['vector'], $row['patterns'], $row['decision_at_ms']);
                        $stacked['vector'] = [...$stacked['vector'], ...$this->patterns->features($patternBundle, $predictions)];
                        unset($stacked['patterns']);
                        $stackedRows[] = $stacked;
                    }
                    unset($row['patterns']);
                }
                unset($row);
            } else {
                foreach ($baseRows as &$row) {
                    unset($row['patterns']);
                }
                unset($row);
            }
            unset($rows);

            $patternTreeSpools = [];
            foreach ($patternBundle['models'] as &$patternModel) {
                $estimator = $patternModel['estimator'] ?? null;
                if ($estimator instanceof SequentialRandomForest) {
                    $patternTreeSpools[] = ['estimator' => $estimator, 'spools' => $estimator->spillTrees()];
                }
            }
            unset($patternModel);

            $leadLagSeries = $leadLag['series'];
            unset($leadLag['series']);
            [$baseRows, $baseLeadLagExcluded] = $this->applyLeadLag($baseRows, $leadLagBundle, $leadLagSeries);
            $stackedLeadLagExcluded = 0;
            if ($stackedRows !== null) {
                [$stackedRows, $stackedLeadLagExcluded] = $this->applyLeadLag($stackedRows, $leadLagBundle, $leadLagSeries);
            }
            unset($leadLagSeries);

            $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
            $knn->validatePreparedRows($baseRows);
            if ($stackedRows !== null) {
                $knn->validatePreparedRows($stackedRows);
            }
            $tuner = new KnnTuner($knn);
            [$baseTraining, $baseTest, $cutoff] = $this->splitRows($baseRows);

            $stageStarted = hrtime(true);
            $selection = $tuner->tunePrepared($baseTraining, $settings, $deadline);
            $rows = $baseRows;
            $training = $baseTraining;
            $test = $baseTest;
            $leadLagExcluded = $baseLeadLagExcluded;

            $comparison = $this->patternAblation->comparisonCandidate($selection);
            if ($stackedRows !== null && $stackedRows !== [] && $comparison !== null) {
                $comparisonK = $comparison['k'];
                $commonBaseTraining = array_values(array_filter($baseTraining,
                    fn (array $row): bool => $row['decision_at_ms'] > $patternKnownAt));
                $stackedTraining = array_values(array_filter($stackedRows,
                    fn (array $row): bool => $row['decision_at_ms'] < $cutoff
                        && $row['label_available_at_ms'] < $cutoff));

                if (count($commonBaseTraining) >= $settings['min_train_size']
                    && count($stackedTraining) === count($commonBaseTraining)) {
                    $technicalComparison = $tuner->evaluateWalkForwardPrepared(
                        $commonBaseTraining, $settings, $comparisonK, $deadline
                    );
                    $stackedComparison = $tuner->evaluateWalkForwardPrepared(
                        $stackedTraining, $settings, $comparisonK, $deadline
                    );
                    $patternAblation = $this->patternAblation->chooseAtK(
                        $technicalComparison['report'], $stackedComparison['report'],
                        $candidatePatternKeys, count($commonBaseTraining)
                    );
                    $patternAblation['common_tuning_rows'] = count($commonBaseTraining);

                    if ($patternAblation['selected'] === 'technical_plus_patterns') {
                        $patternKeys = $candidatePatternKeys;
                        $rows = $stackedRows;
                        $training = $stackedTraining;
                        $test = array_values(array_filter($stackedRows,
                            fn (array $row): bool => $row['decision_at_ms'] >= $cutoff));
                        $leadLagExcluded = $stackedLeadLagExcluded;
                        $selection = $this->replaceSelectionCandidate(
                            $selection, $stackedComparison['report'], $stackedComparison['folds'], $comparisonK
                        );
                    }
                    unset($technicalComparison, $stackedComparison);
                } else {
                    $patternAblation['reason'] = 'insufficient_common_chronological_rows';
                    $patternAblation['common_rows'] = min(count($commonBaseTraining), count($stackedTraining));
                }
                unset($commonBaseTraining, $stackedTraining);
            }
            unset($baseRows, $stackedRows, $baseTraining, $baseTest);
            $buildPerformance['stages']['knn_tuning_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $evaluation = $selection['k'] === null ? null
                : $tuner->evaluatePrepared($training, $test, $selection['k'], $settings, $deadline);
            $buildPerformance['stages']['holdout_ms'] = $this->elapsedMs($stageStarted);

            $tuningRows = count($training);
            $holdoutRows = count($test);
            $holdoutTrainingLabelsAvailableByMs = max(array_column($training, 'label_available_at_ms') ?: [0]);
            $availableAt = max(array_column($rows, 'label_available_at_ms') ?: [0]);
            unset($training, $test, $tuner, $knn);
            foreach ($rows as &$row) {
                $row = [
                    'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                    'vector' => $row['vector'], 'label' => $row['label'], 'feature_weights' => $row['feature_weights'] ?? [],
                ];
            }
            unset($row);
            $knowledge = $rows;
            unset($rows);

            $human = ['version' => HumanGuidance::VERSION, 'status' => 'disabled',
                'reason' => 'excluded_from_scoring', 'influence' => false, 'keys' => [], 'samples' => 0];
            $buildPerformance['stages']['human_guidance_ms'] = 0;
            $stageStarted = hrtime(true);
            $progress = new HumanTrainingProgress(app(ActionLog::class), $manifest);
            $candle = OptionalGuidance::compare('candle', HumanCandleKnn::VERSION, $publicationDeadline,
                fn (float $auxiliaryDeadline): array => $this->candleKnn->train($manifest, $settings, $auxiliaryDeadline, $progress),
                $progress);
            $buildPerformance['stages']['candle_guidance_ms'] = $this->elapsedMs($stageStarted);
            $candle['bundle']['mode'] = 'independent_knn';
            $automaticReady = $selection['k'] !== null && ($evaluation['eligible'] ?? false);
            $automaticReason = $automaticReady ? 'validated' : ($selection['k'] === null ? 'no_eligible_k' : 'holdout_failed');
            $ready = ($automaticReady && $ensemble['weights']['automatic'] > 0)
                || ($candle['bundle']['influence'] && $ensemble['weights']['human_candle'] > 0);
            if ($candle['bundle']['influence']) {
                $availableAt = max($availableAt, $candle['bundle']['available_at_ms']);
            }
            foreach ($patternTreeSpools as &$spilledForest) {
                $spilledForest['estimator']->restoreTrees($spilledForest['spools']);
                $spilledForest['spools'] = [];
            }
            unset($spilledForest);
            $artifact = [
                'validation_version' => self::VERSION,
                'generation_key' => $generation, 'dataset_id' => $dataset, 'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
                'period' => $manifest['period'], 'feature_version' => FeatureEngine::VERSION,
                'normalization' => NormalizedVector::VERSION, 'keys' => $manifest['keys'],
                'lead_lag' => $leadLagBundle, 'lead_lag_keys' => $leadLagBundle['keys'],
                'human_guidance' => $human, 'human_keys' => [],
                'candle_guidance' => $candle['bundle'], 'candle_keys' => [],
                'ensemble' => $ensemble,
                'automatic' => ['status' => $automaticReady ? 'ready' : 'abstaining', 'reason' => $automaticReason,
                    'validation_target' => 'future_semantic_outcomes', 'schema' => $manifest['schema'],
                    'schema_selection' => $schemaSelection ?: ['requested_schema' => $manifest['schema'],
                        'effective_schema' => $manifest['schema'], 'fallback_policy' => 'none', 'reason' => 'frozen_dataset'],
                    'history_status' => $selection['folds'] === [] ? 'insufficient_tuning_history' : 'tuning_evaluated',
                    'pattern_ablation' => $patternAblation],
                'regime_settings' => ['super_confidence' => 0.8, 'super_effective_neighbors' => 6.0],
                'pattern_keys' => $patternKeys, 'label_definition' => $manifest['label_definition'],
                'trained_as_of_ms' => $manifest['as_of_ms'], 'available_at_ms' => $availableAt,
                'source_rows_sha256' => $manifest['rows_sha256'],
                'status' => $ready ? 'ready' : 'abstaining',
                'reason' => $ready ? 'validated' : ($automaticReady ? 'no_weighted_model' : $automaticReason),
                'k' => $selection['k'], 'settings' => $settings,
                'pattern_settings' => $patternSettings,
                'training_data' => [
                    'window' => $window, 'snapshot_rows' => $snapshotRows, 'age_excluded_rows' => $ageExcluded,
                    'deduplication' => $rowAudit,
                    'schema' => $manifest['schema'], 'source_rows' => $sourceRows,
                    'usable_rows' => count($knowledge),
                    'pattern_excluded_rows' => $sourceRows - count($knowledge) - $leadLagExcluded,
                    'human_excluded_rows' => 0, 'candle_excluded_rows' => 0,
                    'lead_lag_excluded_rows' => $leadLagExcluded,
                    'skipped' => $manifest['skipped'] ?? [],
                    'reconstruction' => $manifest['reconstruction'] ?? [],
                    'tuning_rows' => $tuningRows, 'holdout_rows' => $holdoutRows,
                ],
                'selection' => $selection, 'holdout' => $evaluation,
                'holdout_from_ms' => $cutoff,
                'holdout_training_labels_available_by_ms' => $holdoutTrainingLabelsAvailableByMs,
                'knowledge_rows' => count($knowledge), 'knowledge' => $knowledge, 'patterns' => $patternBundle,
                'build_performance' => $buildPerformance,
            ];
            if (microtime(true) > $publicationDeadline) {
                throw new RuntimeException('Intelligence training time budget exceeded before publication.');
            }

            return $this->models->save($artifact);
        } finally {
            if (isset($patternTreeSpools)) {
                foreach ($patternTreeSpools as $spilledForest) {
                    $spilledForest['estimator']->closeSpools($spilledForest['spools']);
                }
            }
            $lock->release();
        }
    }

    private function splitRows(array $rows): array
    {
        $testStart = (int) floor(count($rows) * 0.8);
        $test = array_slice($rows, $testStart);
        $cutoff = $test[0]['decision_at_ms'] ?? 0;
        $training = array_values(array_filter(array_slice($rows, 0, $testStart),
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));

        return [$training, $test, $cutoff];
    }

    private function applyLeadLag(array $rows, array $bundle, array $series): array
    {
        if ($bundle['keys'] === []) {
            return [$rows, 0];
        }
        $before = count($rows);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['decision_at_ms'] > $bundle['available_at_ms']));
        foreach ($rows as &$row) {
            $evidence = $this->leadLag->features($bundle, $series, $row['decision_at_ms']);
            $row['feature_weights'] = [...array_fill(0, count($row['vector']), 1.0), ...$evidence['weights']];
            $row['vector'] = [...$row['vector'], ...$evidence['vector']];
        }
        unset($row);

        return [$rows, $before - count($rows)];
    }

    private function replaceSelectionCandidate(array $selection, array $candidate, array $folds, int $k): array
    {
        $replaced = false;
        foreach ($selection['candidates'] as &$existing) {
            if (($existing['k'] ?? null) === $k) {
                $existing = $candidate;
                $replaced = true;
                break;
            }
        }
        unset($existing);
        if (! $replaced) {
            $selection['candidates'][] = $candidate;
            usort($selection['candidates'], fn (array $a, array $b): int => $a['k'] <=> $b['k']);
        }
        $selection['k'] = $k;
        $selection['folds'] = $folds;
        $selection['selection'] = 'pattern_ablation_same_k_then_holdout';

        return $selection;
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
