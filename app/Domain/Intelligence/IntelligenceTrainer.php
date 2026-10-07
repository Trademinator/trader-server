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
    public const VERSION = 'm5-two-knn-v1';

    public function __construct(
        private DatasetStore $datasets,
        private ModelStore $models,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private HumanCandleKnn $candleKnn,
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
            $leadLagExcluded = 0;
            $patternKeys = $patternSettings['as_knn_features'] ? $this->patterns->featureKeys($patternBundle) : [];
            if ($patternKeys !== []) {
                $knownAt = max(array_column($patternBundle['models'], 'available_at_ms'));
                $rows = array_values(array_filter($rows, fn (array $row): bool => $row['decision_at_ms'] > $knownAt));
                foreach ($rows as &$row) {
                    $predictions = $this->patterns->predict($patternBundle, $row['vector'], $row['patterns'], $row['decision_at_ms']);
                    $row['vector'] = [...$row['vector'], ...$this->patterns->features($patternBundle, $predictions)];
                }
                unset($row);
            }
            foreach ($rows as &$row) {
                unset($row['patterns']);
            }
            unset($row);
            $patternTreeSpools = [];
            foreach ($patternBundle['models'] as &$patternModel) {
                $estimator = $patternModel['estimator'] ?? null;
                if ($estimator instanceof SequentialRandomForest) {
                    $patternTreeSpools[] = ['estimator' => $estimator, 'spools' => $estimator->spillTrees()];
                }
            }
            unset($patternModel);
            if ($leadLagBundle['keys'] !== []) {
                $before = count($rows);
                $rows = array_values(array_filter($rows, fn (array $row): bool => $row['decision_at_ms'] > $leadLagBundle['available_at_ms']));
                $leadLagExcluded = $before - count($rows);
                foreach ($rows as &$row) {
                    $evidence = $this->leadLag->features($leadLagBundle, $leadLag['series'], $row['decision_at_ms']);
                    $row['feature_weights'] = [...array_fill(0, count($row['vector']), 1.0), ...$evidence['weights']];
                    $row['vector'] = [...$row['vector'], ...$evidence['vector']];
                }
                unset($row);
            }
            unset($leadLag['series']);
            $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
            $knn->validatePreparedRows($rows);
            $tuner = new KnnTuner($knn);
            $testStart = (int) floor(count($rows) * 0.8);
            $test = array_slice($rows, $testStart);
            $cutoff = $test[0]['decision_at_ms'] ?? 0;
            $training = array_values(array_filter(array_slice($rows, 0, $testStart),
                fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));

            $stageStarted = hrtime(true);
            $selection = $tuner->tunePrepared($training, $settings, $deadline);
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
                    'history_status' => $selection['folds'] === [] ? 'insufficient_tuning_history' : 'tuning_evaluated'],
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

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
