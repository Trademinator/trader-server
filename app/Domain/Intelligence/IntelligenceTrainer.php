<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class IntelligenceTrainer
{
    public const VERSION = 'm4.1-age-window-v3';

    public function __construct(
        private DatasetStore $datasets,
        private ModelStore $models,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private HumanGuidance $humanGuidance,
        private CandleGuidance $candleGuidance,
    ) {}

    public function train(string $dataset, ?float $deadline = null, ?string $generation = null, array $buildPerformance = []): array
    {
        $buildPerformance['started_at'] ??= now()->toIso8601String();
        $buildPerformance['started_monotonic_ns'] ??= hrtime(true);
        $buildPerformance['stages'] ??= [];
        $datasetLoadStarted = hrtime(true);
        [$manifest, $rows] = $this->datasets->load($dataset);
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
        $snapshotRows = count($rows);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['decision_at_ms'] >= $window['from_ms']));
        $ageExcluded = $snapshotRows - count($rows);
        if ($rows === []) {
            throw new InvalidArgumentException('No eligible history within INTELLIGENCE_MAX_MODEL_AGE_DAYS.');
        }
        $rowAudit = TrainingRowAudit::inspect($rows);
        $rows = $rowAudit['rows'];
        unset($rowAudit['rows']);
        $settings = config('intelligence.knn');
        $deadline ??= microtime(true) + config('intelligence.max_seconds');
        $lock = Cache::lock('trademinator:intelligence:'.ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']), 720);
        if (! $lock->get()) {
            throw new RuntimeException('Intelligence training is already running for this market and period.');
        }
        try {
            foreach ($rows as &$row) {
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
                $row['vector'] = NormalizedVector::from($row['vector'], $manifest['keys']);
            }
            unset($row);
            $patternSettings = config('intelligence.patterns');
            $sourceRows = count($rows);
            $patternBundle = ['models' => [], 'report' => [], 'version' => PatternCatalog::VERSION];
            $stageStarted = hrtime(true);
            if ($patternSettings['enabled']) {
                $patternRows = array_slice($rows, 0, (int) floor(count($rows) * 0.4));
                $patternBundle = $this->patterns->train($patternRows, $patternSettings, $deadline);
            }
            $buildPerformance['stages']['patterns_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $leadLag = $this->leadLag->prepare($manifest, $rows, $deadline);
            $buildPerformance['stages']['lead_lag_ms'] = $this->elapsedMs($stageStarted);
            $leadLagBundle = $leadLag['bundle'];
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
            $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
            $tuner = new KnnTuner($knn);
            $testStart = (int) floor(count($rows) * 0.8);
            $test = array_slice($rows, $testStart);
            $cutoff = $test[0]['decision_at_ms'] ?? 0;
            $training = array_values(array_filter(array_slice($rows, 0, $testStart),
                fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));

            $stageStarted = hrtime(true);
            $selection = $tuner->tune($training, $settings, $deadline);
            $buildPerformance['stages']['knn_tuning_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $evaluation = $selection['k'] === null ? null
                : $tuner->evaluate($training, $test, $selection['k'], $settings, $deadline);
            $buildPerformance['stages']['holdout_ms'] = $this->elapsedMs($stageStarted);

            $stageStarted = hrtime(true);
            $human = OptionalGuidance::compare('trend', HumanGuidance::VERSION, $deadline,
                fn (float $auxiliaryDeadline): array => $this->humanGuidance->compare($manifest, $rows, $settings, $auxiliaryDeadline));
            $buildPerformance['stages']['human_guidance_ms'] = $this->elapsedMs($stageStarted);
            $humanExcluded = 0;
            if ($human['bundle']['influence']) {
                $humanExcluded = count($rows) - count($human['rows']);
                $rows = $human['rows'];
                $selection = $human['selection'];
                $evaluation = $human['holdout'];
                $training = $human['training'];
                $test = $human['test'];
                $cutoff = $human['cutoff'];
            }
            $stageStarted = hrtime(true);
            $candle = OptionalGuidance::compare('candle', CandleGuidance::VERSION, $deadline,
                fn (float $auxiliaryDeadline): array => $this->candleGuidance->compare($manifest, $rows, $settings, $auxiliaryDeadline));
            $buildPerformance['stages']['candle_guidance_ms'] = $this->elapsedMs($stageStarted);
            $candleExcluded = 0;
            if ($candle['bundle']['influence']) {
                $candleExcluded = count($rows) - count($candle['rows']);
                $rows = $candle['rows'];
                $selection = $candle['selection'];
                $evaluation = $candle['holdout'];
                $training = $candle['training'];
                $test = $candle['test'];
                $cutoff = $candle['cutoff'];
            }
            $ready = $selection['k'] !== null && ($evaluation['eligible'] ?? false);
            $availableAt = max(array_column($rows, 'label_available_at_ms') ?: [0]);
            if ($human['bundle']['influence']) {
                $availableAt = max($availableAt, $human['bundle']['reviews_submitted_by_ms']);
            }
            if ($candle['bundle']['influence']) {
                $availableAt = max($availableAt, $candle['bundle']['labels_updated_by_ms']);
            }
            $knowledge = array_map(fn (array $row): array => [
                'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => $row['vector'], 'label' => $row['label'], 'feature_weights' => $row['feature_weights'] ?? [],
            ], $rows);
            $artifact = [
                'validation_version' => self::VERSION,
                'generation_key' => $generation, 'dataset_id' => $dataset, 'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
                'period' => $manifest['period'], 'feature_version' => FeatureEngine::VERSION,
                'normalization' => NormalizedVector::VERSION, 'keys' => $manifest['keys'],
                'lead_lag' => $leadLagBundle, 'lead_lag_keys' => $leadLagBundle['keys'],
                'human_guidance' => $human['bundle'], 'human_keys' => $human['bundle']['keys'],
                'candle_guidance' => $candle['bundle'], 'candle_keys' => $candle['bundle']['keys'],
                'regime_settings' => ['super_confidence' => 0.8, 'super_effective_neighbors' => 6.0],
                'pattern_keys' => $patternKeys, 'label_definition' => $manifest['label_definition'],
                'trained_as_of_ms' => $manifest['as_of_ms'], 'available_at_ms' => $availableAt,
                'source_rows_sha256' => $manifest['rows_sha256'],
                'status' => $ready ? 'ready' : 'abstaining',
                'reason' => $ready ? 'validated' : ($selection['k'] === null ? 'no_eligible_k' : 'holdout_failed'),
                'k' => $selection['k'], 'settings' => $settings,
                'pattern_settings' => $patternSettings,
                'training_data' => [
                    'window' => $window, 'snapshot_rows' => $snapshotRows, 'age_excluded_rows' => $ageExcluded,
                    'deduplication' => $rowAudit,
                    'schema' => $manifest['schema'], 'source_rows' => $sourceRows,
                    'usable_rows' => count($rows),
                    'pattern_excluded_rows' => $sourceRows - count($rows) - $leadLagExcluded - $humanExcluded - $candleExcluded,
                    'human_excluded_rows' => $humanExcluded, 'candle_excluded_rows' => $candleExcluded,
                    'lead_lag_excluded_rows' => $leadLagExcluded,
                    'skipped' => $manifest['skipped'] ?? [],
                    'tuning_rows' => count($training), 'holdout_rows' => count($test),
                ],
                'selection' => $selection, 'holdout' => $evaluation,
                'holdout_from_ms' => $cutoff,
                'holdout_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms') ?: [0]),
                'knowledge_rows' => count($knowledge), 'knowledge' => $knowledge, 'patterns' => $patternBundle,
                'build_performance' => $buildPerformance,
            ];
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Intelligence training time budget exceeded before publication.');
            }

            return $this->models->save($artifact);
        } finally {
            $lock->release();
        }
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
