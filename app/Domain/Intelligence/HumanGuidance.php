<?php

namespace App\Domain\Intelligence;

use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use RuntimeException;

/** Human opinions are auxiliary inputs. Every downstream target remains objective. */
final class HumanGuidance
{
    public const VERSION = 'm4.4-human-guidance-v1';

    public function compare(array $manifest, array $rows, array $settings, float $deadline): array
    {
        $bundle = ['version' => self::VERSION, 'status' => 'insufficient_human_labels', 'keys' => [],
            'samples' => 0, 'evaluation_mode' => 'retrospective_chronological_research',
            'annotation_cutoff_ms' => now()->getTimestampMs(), 'influence' => false];
        if (! config('human_training.enabled') || count($rows) < 5) {
            $bundle['status'] = 'disabled_or_insufficient_history';

            return ['bundle' => $bundle];
        }
        $prefixEnd = (int) floor(count($rows) * 0.4);
        $later = array_slice($rows, $prefixEnd);
        $cutoff = $later[0]['decision_at_ms'];
        $prefix = array_values(array_filter(array_slice($rows, 0, $prefixEnd),
            fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
        $opinions = $this->opinions($manifest, $prefix, $bundle['annotation_cutoff_ms']);
        $bundle['samples'] = count($opinions);
        $bundle['minimum_samples'] = config('human_training.min_samples');
        if (count($opinions) < config('human_training.min_samples')
            || count(array_unique(array_column($opinions, 'label'))) < 2) {
            return ['bundle' => $bundle];
        }
        $this->deadline($deadline);
        $k = min(config('human_training.k'), count($opinions));
        $estimator = new KNearestNeighbors($k, true);
        $estimator->train(new Labeled(array_column($opinions, 'vector'), array_column($opinions, 'label')));
        $bundle = [...$bundle, 'estimator' => $estimator, 'k' => $k,
            'input_keys' => $manifest['keys'], 'keys' => array_map(fn (string $label): string => 'human.'.$label, HumanTraining::LABELS),
            'training_through_ms' => max(array_column($opinions, 'decision_at_ms')),
            'training_outcomes_available_by_ms' => max(array_column($opinions, 'label_available_at_ms')),
            'reviews_submitted_by_ms' => max(array_column($opinions, 'submitted_at_ms')),
            'downstream_from_ms' => $cutoff,
            'label_provenance_sha256' => HumanTrainingSnapshot::digest(array_column($opinions, 'provenance'))];
        $combined = [];
        foreach ($later as $row) {
            $this->deadline($deadline);
            $probabilities = $this->features($bundle, array_slice($row['vector'], 0, count($manifest['keys'])));
            $combined[] = [...$row,
                'feature_weights' => [...($row['feature_weights'] ?? array_fill(0, count($row['vector']), 1.0)), ...array_fill(0, count($probabilities), 1.0)],
                'vector' => [...$row['vector'], ...$probabilities]];
        }
        $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
        $tuner = new KnnTuner($knn);
        $machine = $this->validate($later, $settings, $tuner, $deadline);
        $hybrid = $this->validate($combined, $settings, $tuner, $deadline);
        $humanPredictions = [];
        foreach ($machine['test'] as $row) {
            $probabilities = $this->features($bundle, array_slice($row['vector'], 0, count($manifest['keys'])));
            $votes = ['buy' => $probabilities[0] + $probabilities[1], 'hodl' => $probabilities[2], 'sell' => $probabilities[3] + $probabilities[4]];
            arsort($votes);
            $values = array_values($votes);
            $supported = $values[0] >= $settings['min_confidence'] && $values[0] - $values[1] > 1e-12;
            $humanPredictions[] = ['action' => $supported ? array_key_first($votes) : 'hodl', 'confidence' => $supported ? $values[0] : 0.0];
        }
        $humanReport = $tuner->evaluatePredictions($machine['test'], $humanPredictions, $settings);
        $selectionPassed = $this->improves($machine['selected_score'], $hybrid['selected_score']);
        $holdoutPassed = $this->improves($machine['holdout'], $hybrid['holdout']);
        $accepted = $selectionPassed && $holdoutPassed;
        $bundle = [...$bundle, 'influence' => $accepted,
            'status' => $accepted ? 'validated' : ($selectionPassed ? 'holdout_did_not_improve' : 'tuning_did_not_improve'),
            'selection_passed' => $selectionPassed, 'holdout_passed' => $holdoutPassed,
            'minimum_precision_gain' => config('human_training.min_precision_gain'),
            'holdout_from_ms' => $machine['cutoff'],
            'comparison' => ['machine_only' => ['selection' => $machine['selection'], 'holdout' => $machine['holdout']],
                'human_only' => ['holdout' => $humanReport, 'production_eligible' => false],
                'combined' => ['selection' => $hybrid['selection'], 'holdout' => $hybrid['holdout']]]];
        if (! $accepted) {
            unset($bundle['estimator']);
            $bundle['keys'] = [];

            return ['bundle' => $bundle];
        }

        return ['bundle' => $bundle, 'rows' => $combined, ...$hybrid];
    }

    private function opinions(array $manifest, array $rows, int $annotationCutoff): array
    {
        if ($rows === []) {
            return [];
        }
        $trainerIds = array_map('strtolower', array_filter([config('operations.owner_uuid'), ...config('human_training.trainer_uuids')]));
        $trainers = User::query()->whereIn('user_id', $trainerIds)
            ->get()->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))->pluck('user_id')->all();
        if ($trainers === []) {
            return [];
        }
        $byTime = array_column($rows, null, 'decision_at_ms');
        $snapshots = HumanTrainingSnapshot::query()->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)->whereIn('decision_at_ms', array_keys($byTime))
            ->with(['reviews' => fn ($query) => $query->whereIn('trainer_id', $trainers)->whereIn('label', HumanTraining::LABELS)
                ->whereNotNull('submitted_at')->where('submitted_at', '<=', CarbonImmutable::createFromTimestampMs($annotationCutoff)->format('Y-m-d H:i:s.v'))->orderBy('trainer_id')])
            ->orderBy('decision_at_ms')->orderBy('snapshot_id')->lazy(25)->take((int) config('intelligence.max_rows'));
        $opinions = [];
        foreach ($snapshots as $snapshot) {
            $row = $byTime[$snapshot->decision_at_ms] ?? null;
            $payload = $snapshot->verifiedPayload();
            if ($row === null || $payload['feature_version'] !== $manifest['feature_version']
                || $payload['keys'] !== $manifest['keys'] || $payload['normalization'] !== NormalizedVector::VERSION
                || $payload['horizon_candles'] !== $manifest['label_definition']['horizon']
                || $payload['vector'] != array_slice($row['vector'], 0, count($manifest['keys']))
                || ($payload['feature_sha256'] ?? null) !== ($row['source']['feature_sha256'] ?? null)) {
                continue;
            }
            $votes = $snapshot->reviews->countBy('label')->sortDesc();
            $count = $snapshot->reviews->count();
            $top = $votes->first() ?? 0;
            if ($count < config('human_training.min_reviewers') || $top / max(1, $count) < config('human_training.min_agreement')
                || $votes->values()->get(1) === $top) {
                continue;
            }
            $opinions[] = ['vector' => $payload['vector'], 'label' => $votes->keys()->first(),
                'decision_at_ms' => $row['decision_at_ms'], 'label_available_at_ms' => $row['label_available_at_ms'],
                'submitted_at_ms' => $snapshot->reviews->max(fn ($review) => $review->submitted_at->getTimestampMs()),
                'provenance' => ['snapshot_id' => $snapshot->snapshot_id, 'sha256' => $snapshot->sha256,
                    'reviews' => $snapshot->reviews->map(fn ($review): array => ['id' => $review->review_id,
                        'trainer_id' => $review->trainer_id, 'label' => $review->label,
                        'submitted_at_ms' => $review->submitted_at->getTimestampMs()])->all()]];
        }

        return $opinions;
    }

    private function validate(array $rows, array $settings, KnnTuner $tuner, float $deadline): array
    {
        $start = (int) floor(count($rows) * 0.8);
        $test = array_slice($rows, $start);
        $cutoff = $test[0]['decision_at_ms'];
        $training = array_values(array_filter(array_slice($rows, 0, $start), fn (array $row): bool => $row['label_available_at_ms'] < $cutoff));
        $selection = $tuner->tune($training, $settings, $deadline);
        $score = collect($selection['candidates'])->firstWhere('k', $selection['k']);
        $holdout = $selection['k'] === null
            ? $tuner->evaluatePredictions($test, array_fill(0, count($test), WeightedKnn::abstain('no_eligible_k')), $settings)
            : $tuner->evaluate(array_slice($training, -$settings['train_size']), $test, $selection['k'], $settings, $deadline);

        return ['selection' => $selection, 'selected_score' => $score, 'holdout' => $holdout,
            'test' => $test, 'training' => $training, 'cutoff' => $cutoff];
    }

    public function improves(?array $machine, ?array $combined): bool
    {
        return ($combined['eligible'] ?? false)
            && $combined['semantic_precision'] >= ($machine['semantic_precision'] ?? 0) + max(0.000001, config('human_training.min_precision_gain'))
            && $combined['coverage'] >= ($machine['coverage'] ?? 0)
            && $combined['contradiction_rate'] <= ($machine['contradiction_rate'] ?? config('intelligence.knn.max_contradiction_rate'));
    }

    /** Ordered opinion shares, not calibrated probabilities of price movement or profit. */
    public function features(array $bundle, array $vector): array
    {
        $probabilities = $bundle['estimator']->proba(new Unlabeled([$vector]))[0];

        return array_map(fn (string $label): float => (float) ($probabilities[$label] ?? 0.0), HumanTraining::LABELS);
    }

    private function deadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Human guidance training time budget exceeded.');
        }
    }
}
