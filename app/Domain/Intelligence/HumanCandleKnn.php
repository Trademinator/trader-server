<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/** Independent technical KNN whose targets are authorized human candle annotations. */
final class HumanCandleKnn
{
    public const VERSION = 'm5-human-candle-knn-v1';

    public function __construct(private DatasetStore $datasets, private HumanTraining $snapshots) {}

    public function train(array $manifest, array $settings, float $deadline): array
    {
        $keys = array_values(array_intersect($manifest['keys'], FeatureEngine::KEYS));
        $settings = array_replace($settings, config('human_training.candle_validation', []));
        $bundle = ['version' => self::VERSION, 'mode' => 'independent_knn', 'status' => 'insufficient_candle_labels',
            'keys' => [], 'input_keys' => $keys, 'influence' => false, 'samples' => 0,
            'validation_target' => 'human_candle_annotations', 'evaluation_mode' => 'retrospective_chronological_research',
            'annotation_cutoff_ms' => now()->getTimestampMs(), 'settings' => $settings,
            'window' => KnowledgeWindow::metadata($manifest['as_of_ms']),
            'minimum_samples' => config('human_training.candle_min_samples')];
        if ($keys === []) {
            return ['bundle' => [...$bundle, 'status' => 'no_technical_features']];
        }
        $rows = $this->opinions($manifest, $keys, $bundle['annotation_cutoff_ms'], $deadline);
        $bundle = [...$bundle, 'samples' => count($rows), 'class_counts' => $this->counts($rows),
            'sampling' => 'all_eligible_distinct_candles'];
        if (count($rows) < max(5, (int) config('human_training.candle_min_samples'))) {
            return ['bundle' => $bundle];
        }
        if (count(array_filter($bundle['class_counts'])) < 2) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_action_diversity']];
        }
        $knn = $this->knn($settings);
        $rows = $knn->prepareRows($rows);
        $tuningStart = (int) floor(count($rows) * 0.6);
        $holdoutStart = (int) floor(count($rows) * 0.8);
        $tuning = array_slice($rows, $tuningStart, $holdoutStart - $tuningStart);
        $holdout = array_slice($rows, $holdoutStart);
        $training = $this->purge(array_slice($rows, 0, $tuningStart), $tuning[0]['decision_at_ms']);
        if ($training === []) {
            return ['bundle' => [...$bundle, 'status' => 'insufficient_history']];
        }
        $k = min((int) config('human_training.candle_k'), count($training));
        if ($k < 1) {
            throw new InvalidArgumentException('Human Candle K must be positive.');
        }
        $selected = null;
        foreach (['natural' => null, 'target_priors' => config('human_training.candle_target_weights')] as $policy => $target) {
            $weights = ClassPriorWeights::fit($this->counts($training), $target);
            $score = $this->evaluate($knn, $training, $tuning, $k, $weights, $settings, $deadline);
            $bundle['weight_candidates'][$policy] = ['class_weights' => $weights, 'tuning' => $score];
            if ($score['eligible'] && ($selected === null || $this->rank($score) > $this->rank($selected['tuning']))) {
                $selected = ['policy' => $policy, 'target' => $target, 'tuning' => $score];
            }
        }
        $bundle = [...$bundle, 'k' => $k, 'tuning_from_ms' => $tuning[0]['decision_at_ms'],
            'holdout_from_ms' => $holdout[0]['decision_at_ms'], 'selection_passed' => $selected !== null,
            'tuning_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
            'label_provenance_sha256' => HumanTrainingSnapshot::digest(array_column($rows, 'provenance'))];
        if ($selected === null) {
            return ['bundle' => [...$bundle, 'status' => 'tuning_failed']];
        }
        // Fix the policy before touching the final holdout; never try a runner-up afterward.
        $training = $this->purge(array_slice($rows, 0, $holdoutStart), $holdout[0]['decision_at_ms']);
        $weights = ClassPriorWeights::fit($this->counts($training), $selected['target']);
        $score = $this->evaluate($knn, $training, $holdout, $k, $weights, $settings, $deadline);
        $bundle = [...$bundle, 'weight_policy' => $selected['policy'], 'tuning' => $selected['tuning'],
            'holdout' => $score, 'holdout_passed' => $score['eligible'],
            'holdout_training_labels_available_by_ms' => max(array_column($training, 'label_available_at_ms')),
            'status' => $score['eligible'] ? 'validated' : 'holdout_failed', 'influence' => $score['eligible']];
        if (! $score['eligible']) {
            return ['bundle' => $bundle];
        }
        // Publication retains all eligible annotations, including the evaluated period.
        $knowledge = array_map(fn (array $row): array => [
            'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
            'vector' => $row['vector'], 'label' => $row['label'],
        ], $rows);
        $updatedBy = max(array_column($rows, 'updated_at_ms'));

        return ['bundle' => [...$bundle,
            'class_weights' => ClassPriorWeights::fit($this->counts($rows), $selected['target']),
            'knowledge_rows' => count($knowledge), 'knowledge' => $knowledge,
            'labels_updated_by_ms' => $updatedBy,
            'available_at_ms' => max($updatedBy, max(array_column($rows, 'label_available_at_ms'))),
            'training_through_ms' => max(array_column($rows, 'decision_at_ms'))]];
    }

    public function predict(array $bundle, array $payload, int $asOfMs): array
    {
        if (! OptionalGuidance::enabled('candle')) {
            return WeightedKnn::abstain('candle_training_disabled');
        }
        if (($bundle['version'] ?? null) !== self::VERSION) {
            return WeightedKnn::abstain('candle_model_version_mismatch');
        }
        if (! ($bundle['influence'] ?? false)) {
            return WeightedKnn::abstain($bundle['status'] ?? 'candle_model_unavailable');
        }
        if (($bundle['available_at_ms'] ?? PHP_INT_MAX) >= $asOfMs) {
            return WeightedKnn::abstain('no_post_annotation_candle');
        }
        $vector = FeatureSchema::vector($payload, $bundle['input_keys']);
        if ($vector === null) {
            return WeightedKnn::abstain('missing_technical_features');
        }
        $knn = $this->knn($bundle['settings']);
        $neighbors = $knn->neighbors($bundle['knowledge'], NormalizedVector::from($vector, $bundle['input_keys']), $bundle['k'], $asOfMs);

        return $knn->vote($neighbors, $bundle['k'], $this->voteWeights($bundle['class_weights']));
    }

    private function opinions(array $manifest, array $keys, int $annotationCutoff, float $deadline): array
    {
        $trainerIds = array_map('strtolower', array_filter([...User::ownerIds(), ...config('human_training.trainer_uuids')]));
        $trainers = User::query()->whereIn('user_id', $trainerIds)->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))->pluck('user_id')->all();
        if ($trainers === []) {
            return [];
        }
        $cutoff = CarbonImmutable::createFromTimestampMs($annotationCutoff)->format('Y-m-d H:i:s.v');
        $snapshots = HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)
            ->whereBetween('decision_at_ms', [KnowledgeWindow::fromMs($manifest['as_of_ms']), $manifest['as_of_ms']])
            ->whereHas('candleLabels', fn ($query) => $query->whereIn('trainer_id', $trainers)->where('updated_at', '<=', $cutoff))
            ->with(['candleLabels' => fn ($query) => $query->whereIn('trainer_id', $trainers)
                ->whereIn('action', CandleTraining::ACTIONS)->where('updated_at', '<=', $cutoff)->orderBy('trainer_id')])
            // Keep each source contiguous so its full checksum-verified dataset is loaded once.
            ->orderBy('dataset_id')->orderBy('decision_at_ms')->orderByDesc('snapshot_id')->lazy(25);
        $opinions = [];
        $loadedDataset = null;
        $source = $rawRows = $byTime = [];
        foreach ($snapshots->chunk(25) as $batch) {
            foreach ($batch->groupBy('dataset_id') as $dataset => $group) {
                $this->deadline($deadline);
                // Use each annotation's original schema when checking its immutable chart.
                // Technical annotations remain usable when the automatic schema adds context.
                if ($dataset !== $loadedDataset) {
                    [$source, $rawRows] = $this->datasets->load($dataset);
                    $byTime = array_column($rawRows, null, 'decision_at_ms');
                    $loadedDataset = $dataset;
                }
                if ([$source['exchange'], $source['symbol'], $source['period'], $source['feature_version']]
                    !== [$manifest['exchange'], $manifest['symbol'], $manifest['period'], $manifest['feature_version']]
                    || $source['label_definition']['horizon'] !== $manifest['label_definition']['horizon']) {
                    continue;
                }
                $compatible = $this->snapshots->compatibleSnapshotIds($source, $group, $rawRows);
                foreach ($group as $snapshot) {
                    $this->deadline($deadline);
                    $decision = $snapshot->decision_at_ms;
                    if (! isset($compatible[$snapshot->snapshot_id])
                        || (isset($opinions[$decision]) && strcmp($opinions[$decision]['provenance']['snapshot_id'], $snapshot->snapshot_id) >= 0)) {
                        continue;
                    }
                    $row = $byTime[$decision] ?? null;
                    $payload = $snapshot->verifiedPayload();
                    if ($row === null || $row['label_available_at_ms'] > $manifest['as_of_ms']
                        || ($payload['feature_version'] ?? null) !== $source['feature_version']
                        || ($payload['keys'] ?? null) !== $source['keys']
                        || ($payload['normalization'] ?? null) !== NormalizedVector::VERSION
                        || ($payload['horizon_candles'] ?? null) !== $source['label_definition']['horizon']
                        || ($payload['vector'] ?? null) != NormalizedVector::from($row['vector'], $source['keys'])
                        || ($payload['features'] ?? null) != array_combine($source['keys'], $row['vector'])
                        || ($payload['feature_sha256'] ?? null) !== ($row['source']['feature_sha256'] ?? null)) {
                        continue;
                    }
                    $vector = FeatureSchema::vector($payload, $keys);
                    if ($vector === null) {
                        continue;
                    }
                    $votes = $snapshot->candleLabels->countBy('action')->sortDesc();
                    $count = $snapshot->candleLabels->count();
                    $top = $votes->first() ?? 0;
                    if ($count < config('human_training.min_reviewers') || $top / max(1, $count) < config('human_training.min_agreement')
                        || $votes->values()->get(1) === $top) {
                        continue;
                    }
                    $action = $votes->keys()->first();
                    $opinions[$decision] = ['vector' => NormalizedVector::from($vector, $keys),
                        'label' => $action === 'hold' ? 'hodl' : $action,
                        // Validation targets describe annotations, not objective market outcomes.
                        'semantic' => ['bottom' => $action === 'buy', 'top' => $action === 'sell'],
                        'decision_at_ms' => $decision, 'label_available_at_ms' => $row['label_available_at_ms'],
                        'updated_at_ms' => $snapshot->candleLabels->max(fn ($label) => $label->updated_at->getTimestampMs()),
                        'provenance' => ['snapshot_id' => $snapshot->snapshot_id, 'sha256' => $snapshot->sha256,
                            'labels' => $snapshot->candleLabels->map(fn ($label): array => ['id' => $label->candle_label_id,
                                'trainer_id' => $label->trainer_id, 'action' => $label->action,
                                'updated_at_ms' => $label->updated_at->getTimestampMs()])->all()]];
                }
            }
        }
        ksort($opinions);

        return array_values($opinions);
    }

    private function evaluate(WeightedKnn $knn, array $training, array $test, int $k, array $weights, array $settings, float $deadline): array
    {
        $predictions = [];
        foreach ($test as $row) {
            $this->deadline($deadline);
            $predictions[] = $knn->vote($knn->neighborsPrepared($training, $row['vector'], $k, $row['decision_at_ms']), $k, $this->voteWeights($weights));
        }
        $score = (new KnnTuner($knn))->evaluatePredictions($test, $predictions, $settings);
        $score['k'] = $k;
        $score['directional_annotation_agreement'] = $score['semantic_precision'];
        $score['opposite_annotation_rate'] = $score['contradiction_rate'];
        unset($score['semantic_precision'], $score['contradiction_rate']);

        return $score;
    }

    private function rank(array $score): array
    {
        return [$score['directional_annotation_agreement'], $score['coverage'], -$score['opposite_annotation_rate'], $score['mean_confidence']];
    }

    private function purge(array $rows, int $cutoff): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
    }

    private function counts(array $rows): array
    {
        $counts = array_fill_keys(CandleTraining::ACTIONS, 0);
        foreach ($rows as $row) {
            $counts[$row['label'] === 'hodl' ? 'hold' : $row['label']]++;
        }

        return $counts;
    }

    private function voteWeights(array $weights): array
    {
        return ['buy' => $weights['buy'], 'hodl' => $weights['hold'], 'sell' => $weights['sell']];
    }

    private function knn(array $settings): WeightedKnn
    {
        return new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Candle guidance training time budget exceeded.');
        }
    }
}
