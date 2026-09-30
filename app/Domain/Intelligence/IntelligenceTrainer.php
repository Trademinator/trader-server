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
    public const VERSION = 'm4.1-chronological-v2';

    public function __construct(
        private DatasetStore $datasets,
        private ModelStore $models,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private HumanGuidance $humanGuidance,
    ) {}

    public function train(string $dataset, ?float $deadline = null, ?string $generation = null): array
    {
        $manifest = $this->datasets->manifest($dataset);
        if ($manifest['rows'] > config('intelligence.max_rows')) {
            throw new InvalidArgumentException('Dataset exceeds intelligence.max_rows; use a smaller date range.');
        }
        [$manifest, $rows] = $this->datasets->load($dataset, (int) config('intelligence.max_rows'));
        if ($manifest['feature_version'] !== FeatureEngine::VERSION
            || $manifest['label_definition']['version'] !== SemanticLabels::VERSION) {
            throw new InvalidArgumentException('M4 requires current features and cost-free semantic labels; build fresh knowledge.');
        }
        if ($manifest['as_of_ms'] > now()->getTimestampMs()) {
            throw new InvalidArgumentException('Knowledge cutoff cannot be in the future.');
        }
        $settings = config('intelligence.knn');
        if (count($rows) > config('intelligence.max_rows')) {
            throw new InvalidArgumentException('Dataset exceeds intelligence.max_rows; use a smaller date range.');
        }
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
            if ($patternSettings['enabled']) {
                $patternRows = array_slice($rows, 0, (int) floor(count($rows) * 0.4));
                $patternBundle = $this->patterns->train($patternRows, $patternSettings, $deadline);
            }
            $leadLag = $this->leadLag->prepare($manifest, $rows, $deadline);
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
            $selection = $tuner->tune($training, $settings, $deadline);
            $evaluation = $selection['k'] === null ? null
                : $tuner->evaluate(array_slice($training, -$settings['train_size']), $test, $selection['k'], $settings, $deadline);
            $human = $this->humanGuidance->compare($manifest, $rows, $settings, $deadline);
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
            $ready = $selection['k'] !== null && ($evaluation['eligible'] ?? false);
            $availableAt = max(array_column($rows, 'label_available_at_ms') ?: [0]);
            if ($human['bundle']['influence']) {
                $availableAt = max($availableAt, $human['bundle']['reviews_submitted_by_ms']);
            }
            $knowledge = array_map(fn (array $row): array => [
                'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                'vector' => $row['vector'], 'label' => $row['label'], 'feature_weights' => $row['feature_weights'] ?? [],
            ], array_slice($rows, -$settings['train_size']));
            $artifact = [
                'validation_version' => self::VERSION,
                'generation_key' => $generation, 'dataset_id' => $dataset, 'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
                'period' => $manifest['period'], 'feature_version' => FeatureEngine::VERSION,
                'normalization' => NormalizedVector::VERSION, 'keys' => $manifest['keys'],
                'lead_lag' => $leadLagBundle, 'lead_lag_keys' => $leadLagBundle['keys'],
                'human_guidance' => $human['bundle'], 'human_keys' => $human['bundle']['keys'],
                'regime_settings' => ['super_confidence' => 0.8, 'super_effective_neighbors' => 6.0],
                'pattern_keys' => $patternKeys, 'label_definition' => $manifest['label_definition'],
                'trained_as_of_ms' => $manifest['as_of_ms'], 'available_at_ms' => $availableAt,
                'source_rows_sha256' => $manifest['rows_sha256'],
                'status' => $ready ? 'ready' : 'abstaining',
                'reason' => $ready ? 'validated' : ($selection['k'] === null ? 'no_eligible_k' : 'holdout_failed'),
                'k' => $selection['k'], 'settings' => $settings,
                'pattern_settings' => $patternSettings,
                'training_data' => [
                    'schema' => $manifest['schema'], 'source_rows' => $sourceRows,
                    'usable_rows' => count($rows), 'pattern_excluded_rows' => $sourceRows - count($rows) - $leadLagExcluded - $humanExcluded,
                    'human_excluded_rows' => $humanExcluded,
                    'lead_lag_excluded_rows' => $leadLagExcluded,
                    'skipped' => $manifest['skipped'] ?? [],
                    'tuning_rows' => count($training), 'holdout_rows' => count($test),
                ],
                'selection' => $selection, 'holdout' => $evaluation,
                'holdout_from_ms' => $cutoff,
                'holdout_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms') ?: [0]),
                'knowledge_rows' => count($knowledge), 'knowledge' => $knowledge, 'patterns' => $patternBundle,
            ];
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Intelligence training time budget exceeded before publication.');
            }

            return $this->models->save($artifact);
        } finally {
            $lock->release();
        }
    }
}
