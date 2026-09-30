<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CandleTraining
{
    public const ACTIONS = ['buy', 'hold', 'sell'];

    public function __construct(private DatasetStore $datasets, private HumanTraining $snapshots) {}

    public function count(User $trainer): int
    {
        Gate::forUser($trainer)->authorize('train-intelligence');

        return HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)->count();
    }

    public function review(User $trainer, string $dataset, ?int $decisionAtMs = null): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $row = $decisionAtMs === null
            ? $this->unseenRow($trainer, $manifest, $rows)
            : collect($rows)->firstWhere('decision_at_ms', $decisionAtMs);
        if ($row === null) {
            throw ValidationException::withMessages(['decision_at_ms' => 'Choose a candle contained in this dataset.']);
        }
        $snapshot = $this->snapshots->snapshotForRow($manifest, $row);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['decision_at_ms' => 'That candle no longer matches the current source history and cannot be used for training.']);
        }
        $payload = $snapshot->verifiedPayload();
        $rowIndex = array_search($row['decision_at_ms'], array_column($rows, 'decision_at_ms'), true);
        $decisions = [];
        $available = array_column($rows, null, 'microtimestamp');
        foreach ($payload['series'] as $candle) {
            $microtimestamp = $candle['time'] * 1000;
            if (isset($available[$microtimestamp])) {
                $decisions[(string) $candle['time']] = $available[$microtimestamp]['decision_at_ms'];
            }
        }
        $decisionValues = array_values($decisions);
        $fromDecision = $decisionValues === [] ? $row['decision_at_ms'] : min($decisionValues);
        $visibleLabels = HumanTrainingSnapshot::query()
            ->where('market_key', $snapshot->market_key)
            ->where('version', HumanTraining::VERSION)
            ->whereBetween('decision_at_ms', [$fromDecision, $row['decision_at_ms']])
            ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
            ->with(['candleLabels' => fn ($query) => $query->where('trainer_id', $trainer->user_id)])
            ->orderBy('decision_at_ms')->get()
            ->map(function (HumanTrainingSnapshot $item) use ($available, $manifest): ?array {
                $label = $item->candleLabels->first();
                if ($label === null || ! in_array($label->action, self::ACTIONS, true)) {
                    return null;
                }
                $itemPayload = $item->verifiedPayload();
                $source = $available[$itemPayload['microtimestamp']] ?? null;
                if ($source === null || $itemPayload['decision_at_ms'] !== $source['decision_at_ms']
                    || ($itemPayload['feature_version'] ?? null) !== $manifest['feature_version']
                    || ($itemPayload['keys'] ?? null) !== $manifest['keys']
                    || ($itemPayload['normalization'] ?? null) !== NormalizedVector::VERSION
                    || ($itemPayload['horizon_candles'] ?? null) !== $manifest['label_definition']['horizon']
                    || ($itemPayload['vector'] ?? null) != NormalizedVector::from($source['vector'], $manifest['keys'])
                    || ($itemPayload['feature_sha256'] ?? null) !== ($source['source']['feature_sha256'] ?? null)) {
                    return null;
                }

                return ['time' => intdiv($itemPayload['microtimestamp'], 1000), 'action' => $label->action];
            })->filter()->values()->all();
        $label = HumanCandleLabel::query()->where('snapshot_id', $snapshot->snapshot_id)
            ->where('trainer_id', $trainer->user_id)->first();

        return ['manifest' => $manifest, 'snapshot' => $snapshot, 'payload' => $payload,
            'label' => $label, 'visible_labels' => $visibleLabels, 'decisions' => $decisions,
            'previous_decision_at_ms' => is_int($rowIndex) && $rowIndex > 0 ? $rows[$rowIndex - 1]['decision_at_ms'] : null,
            'next_decision_at_ms' => is_int($rowIndex) && $rowIndex + 1 < count($rows) ? $rows[$rowIndex + 1]['decision_at_ms'] : null];
    }

    public function save(User $trainer, string $dataset, int $decisionAtMs, string $action): HumanCandleLabel
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Choose BUY, HOLD or SELL.']);
        }
        $state = $this->review($trainer, $dataset, $decisionAtMs);

        return DB::transaction(function () use ($trainer, $state, $action): HumanCandleLabel {
            $label = HumanCandleLabel::query()->where('snapshot_id', $state['snapshot']->snapshot_id)
                ->where('trainer_id', $trainer->user_id)->lockForUpdate()->first();
            if ($label === null) {
                $label = new HumanCandleLabel;
                $label->forceFill(['snapshot_id' => $state['snapshot']->snapshot_id, 'trainer_id' => $trainer->user_id,
                    'action' => $action]);
                $label->save();

                return $label;
            }
            $label->forceFill(['action' => $action])->save();

            return $label;
        });
    }

    public function delete(User $trainer, string $dataset, int $decisionAtMs): void
    {
        $state = $this->review($trainer, $dataset, $decisionAtMs);
        HumanCandleLabel::query()->where('snapshot_id', $state['snapshot']->snapshot_id)
            ->where('trainer_id', $trainer->user_id)->delete();
    }

    private function load(string $dataset): array
    {
        [$manifest, $rows] = $this->datasets->load($dataset, (int) config('intelligence.max_rows'));
        if (($manifest['feature_version'] ?? null) !== FeatureEngine::VERSION
            || ($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION
            || ($manifest['as_of_ms'] ?? PHP_INT_MAX) > now()->getTimestampMs()
            || $rows === [] || count($rows) > config('intelligence.max_rows')) {
            throw ValidationException::withMessages(['dataset' => 'Choose a current, bounded semantic dataset built from closed candles.']);
        }

        return [$manifest, $rows];
    }

    private function unseenRow(User $trainer, array $manifest, array $rows): array
    {
        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $seen = HumanTrainingSnapshot::query()->where('market_key', $marketKey)
            ->whereBetween('decision_at_ms', [min(array_column($rows, 'decision_at_ms')), max(array_column($rows, 'decision_at_ms'))])
            ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
            ->pluck('decision_at_ms')->flip();
        $candidates = array_values(array_filter($rows, fn (array $row): bool => ! $seen->has($row['decision_at_ms'])));
        if ($candidates === []) {
            $candidates = $rows;
        }
        shuffle($candidates);
        foreach (array_slice($candidates, 0, config('human_training.candidate_attempts')) as $row) {
            if ($this->snapshots->snapshotForRow($manifest, $row) !== null) {
                return $row;
            }
        }

        throw ValidationException::withMessages(['dataset' => 'No intact candle was found in this bounded search. Retry, choose another dataset or collect more history.']);
    }
}
