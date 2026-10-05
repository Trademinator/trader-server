<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\Exchange;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingSnapshot;
use App\Models\User;
use App\Traits\CandleAutoDetection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcdec;

final class CandleTraining
{
    use CandleAutoDetection;

    public const ACTIONS = ['buy', 'hold', 'sell'];

    public const PAGE_SIZE = 50;

    public function __construct(
        private DatasetStore $datasets,
        private HumanTraining $snapshots,
        private MarketCatalog $catalog,
    ) {}

    public function count(User $trainer): int
    {
        Gate::forUser($trainer)->authorize('train-intelligence');

        return HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)->count();
    }

    /** The start POST only chooses its redirect; the GET prepares the chart. */
    public function start(User $trainer, string $dataset): int
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);

        return (int) $this->unseenSnapshot($trainer, $manifest, $rows)['row']['decision_at_ms'];
    }

    public function review(User $trainer, string $dataset, ?int $decisionAtMs = null): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        if ($decisionAtMs === null) {
            $candidate = $this->unseenSnapshot($trainer, $manifest, $rows);
            $row = $candidate['row'];
            $snapshot = $candidate['snapshot'];
        } else {
            $row = collect($rows)->firstWhere('decision_at_ms', $decisionAtMs);
            if ($row === null) {
                throw ValidationException::withMessages(['decision_at_ms' => 'Choose a candle contained in this dataset.']);
            }
            $snapshot = $this->snapshots->snapshotForRow($manifest, $row);
        }
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'decision_at_ms',
                'That candle no longer matches the current source history and cannot be used for training.');
        }
        $payload = $snapshot->verifiedPayload();
        $rowIndex = array_search($row['decision_at_ms'], array_column($rows, 'decision_at_ms'), true);
        $payload['series'] = array_values(array_filter($payload['series'],
            fn (array $candle): bool => $rows[0]['microtimestamp'] <= $candle['time'] * 1000));
        $chart = $this->chartData($trainer, $manifest, $rows, $payload['series']);
        $label = HumanCandleLabel::query()->where('snapshot_id', $snapshot->snapshot_id)
            ->where('trainer_id', $trainer->user_id)->first();

        return ['manifest' => $manifest, 'snapshot' => $snapshot, 'payload' => $payload,
            'label' => $label, 'review_required' => $label === null && ($payload['revision']['requires_review'] ?? false), 'visible_labels' => $chart['labels'], 'decisions' => $chart['decisions'],
            'allowed_actions' => $chart['allowed_actions'],
            'earliest_time' => intdiv($rows[0]['microtimestamp'], 1000),
            'earliest_window_decision_at_ms' => $rows[min(count($rows) - 1,
                max(0, (int) config('human_training.chart_candles') - 1))]['decision_at_ms'],
            'latest_decision_at_ms' => $rows[array_key_last($rows)]['decision_at_ms'],
            'has_more' => $payload['series'][0]['time'] * 1000 > $rows[0]['microtimestamp'],
            'label_stats' => $this->labelStats($trainer, $manifest),
            'taker_fee' => $this->takerFee($manifest),
            'previous_decision_at_ms' => is_int($rowIndex) && $rowIndex > 0 ? $rows[max(0, $rowIndex - self::PAGE_SIZE)]['decision_at_ms'] : null,
            'next_decision_at_ms' => is_int($rowIndex) && $rowIndex + 1 < count($rows) ? $rows[min(count($rows) - 1, $rowIndex + self::PAGE_SIZE)]['decision_at_ms'] : null];
    }

    /** A bounded page of older candles, with only this trainer's compatible labels. */
    public function history(User $trainer, string $dataset, int $decisionAtMs, int $beforeMs): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $replay = collect($rows)->firstWhere('decision_at_ms', $decisionAtMs);
        if ($replay === null || $beforeMs > $replay['microtimestamp']) {
            throw ValidationException::withMessages(['before_ms' => 'Choose history before the current replay candle.']);
        }
        $candidates = array_values(array_filter($rows,
            fn (array $row): bool => $row['microtimestamp'] < $beforeMs && $row['decision_at_ms'] <= $decisionAtMs));
        if ($candidates === []) {
            return ['series' => [], 'labels' => [], 'decisions' => [], 'allowed_actions' => [], 'has_more' => false];
        }
        $snapshot = $this->snapshots->snapshotForRow($manifest, $candidates[array_key_last($candidates)]);
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'before_ms');
        }
        $series = array_slice(array_values(array_filter($snapshot->verifiedPayload()['series'],
            fn (array $candle): bool => $beforeMs > $candle['time'] * 1000
                && $rows[0]['microtimestamp'] <= $candle['time'] * 1000)), -self::PAGE_SIZE);

        return ['series' => $series, ...$this->chartData($trainer, $manifest, $rows, $series),
            'has_more' => $series !== [] && $series[0]['time'] * 1000 > $rows[0]['microtimestamp']];
    }

    /** Advance only within the immutable dataset selected when the page opened. */
    public function nextHistory(User $trainer, string $dataset, int $decisionAtMs, int $afterMs): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $replayIndex = array_search($decisionAtMs, array_column($rows, 'decision_at_ms'), true);
        if ($replayIndex === false || $rows[$replayIndex]['microtimestamp'] !== $afterMs) {
            throw ValidationException::withMessages(['after_ms' => 'Continue from the last loaded replay candle.']);
        }
        if ($replayIndex === array_key_last($rows)) {
            return ['series' => [], 'labels' => [], 'decisions' => [], 'allowed_actions' => [],
                'decision_at_ms' => $decisionAtMs, 'has_more' => false];
        }
        $pageSize = min(self::PAGE_SIZE, max(1, (int) config('human_training.chart_candles')));
        $nextIndex = min(count($rows) - 1, $replayIndex + $pageSize);
        $row = $rows[$nextIndex];
        $snapshot = $this->snapshots->snapshotForRow($manifest, $row);
        if ($snapshot === null) {
            throw CandleTrainingRecovery::exception($trainer, $manifest, 'after_ms');
        }
        $series = array_values(array_filter($snapshot->verifiedPayload()['series'],
            fn (array $candle): bool => $afterMs < $candle['time'] * 1000));

        return ['series' => $series, ...$this->chartData($trainer, $manifest, $rows, $series),
            'decision_at_ms' => $row['decision_at_ms'],
            'has_more' => $nextIndex < count($rows) - 1,
            'previous_decision_at_ms' => $rows[max(0, $nextIndex - self::PAGE_SIZE)]['decision_at_ms'],
            'next_decision_at_ms' => $nextIndex < count($rows) - 1
                ? $rows[min(count($rows) - 1, $nextIndex + self::PAGE_SIZE)]['decision_at_ms'] : null];
    }

    public function save(User $trainer, string $dataset, int $decisionAtMs, string $action): HumanCandleLabel
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Choose BUY, HOLD or SELL.']);
        }
        $state = $this->review($trainer, $dataset, $decisionAtMs);
        $time = intdiv($state['payload']['microtimestamp'], 1000);
        if (! in_array($action, $state['allowed_actions'][(string) $time] ?? [], true)) {
            throw ValidationException::withMessages(['action' => 'BUY requires a red candle (open > close); SELL requires a green candle (open < close). HOLD is allowed on any candle.']);
        }

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


    /** Build retrospective BUY/HOLD/SELL suggestions without storing any label. */
    public function autoLabels(User $trainer, string $dataset, bool $includeExisting = false): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        $takerFee = $this->takerFee($manifest);
        if ($takerFee === null) {
            throw ValidationException::withMessages([
                'dataset' => 'Auto-label requires a published exchange taker fee so unprofitable movements can be removed.',
            ]);
        }

        $tickers = [];
        foreach ($rows as $row) {
            $candle = $row['candle'] ?? null;
            if (! is_array($candle) || array_diff(['open', 'high', 'low', 'close', 'volume'], array_keys($candle)) !== []) {
                throw ValidationException::withMessages(['dataset' => 'This frozen dataset is missing candle values required for auto-labeling.']);
            }
            $tickers[] = [...$candle, 'microtimestamp' => $row['microtimestamp'], 'decision_at_ms' => $row['decision_at_ms']];
        }

        // Order is part of the algorithm: broad labels first, excess removed later.
        $this->candle_anatomy($tickers);
        $this->candle_auto_mark_hold_candidates($tickers);
        $this->mark_all_blacks_and_whites($tickers);
        $this->remove_consequitive_actions($tickers);
        $this->remove_unprofitable_transactions($tickers, $takerFee);
        $this->remove_zigzags($tickers, $takerFee);
        $this->find_new_bottoms($tickers);
        $this->remove_consequitive_actions($tickers);
        $this->hodl_all_dojis($tickers);
        $this->hodl_middle_chains($tickers);

        $existing = collect();
        if (! $includeExisting) {
            $labelled = HumanTrainingSnapshot::query()
                ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
                ->where('version', HumanTraining::VERSION)
                ->whereIn('decision_at_ms', array_column($rows, 'decision_at_ms'))
                ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
                ->orderBy('snapshot_id')->lazy(25);
            foreach ($labelled->chunk(25) as $batch) {
                $compatible = $this->snapshots->compatibleSnapshotIds($manifest, $batch, $rows);
                foreach ($batch as $snapshot) {
                    if (isset($compatible[$snapshot->snapshot_id])) {
                        $existing->put($snapshot->decision_at_ms, true);
                    }
                }
            }
        }

        $labels = [];
        foreach ($tickers as $ticker) {
            $action = $ticker['action'] ?? null;
            if (! in_array($action, self::ACTIONS, true) || $existing->has($ticker['decision_at_ms'])) {
                continue;
            }
            $labels[] = [
                'time' => intdiv((int) $ticker['microtimestamp'], 1000),
                'decision_at_ms' => (int) $ticker['decision_at_ms'],
                'action' => $action,
            ];
        }

        return ['labels' => $labels, 'count' => count($labels)];
    }

    /**
     * Persist the browser-reviewed final delta in one transaction. Until this
     * method is called, manual clicks, auto-label suggestions and bulk deletion
     * exist only in the browser and cannot influence a model build.
     */
    public function submitLabels(User $trainer, string $dataset, array $changes, bool $deleteAll = false): array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        [$manifest, $rows] = $this->load($dataset);
        if (count($changes) > (int) config('intelligence.max_rows')) {
            throw ValidationException::withMessages(['changes' => 'Too many candle-label changes were submitted at once.']);
        }

        $rowsByDecision = array_column($rows, null, 'decision_at_ms');
        $prepared = [];
        foreach ($changes as $change) {
            $decision = (int) ($change['decision_at_ms'] ?? 0);
            $action = $change['action'] ?? null;
            $row = $rowsByDecision[$decision] ?? null;
            if ($row === null || ($action !== null && ! in_array($action, self::ACTIONS, true))) {
                throw ValidationException::withMessages(['changes' => 'A staged candle label no longer belongs to this frozen dataset.']);
            }
            if ($action !== null && ! in_array($action, $this->allowedActions($row['candle']), true)) {
                throw ValidationException::withMessages([
                    'changes' => 'BUY requires a red candle; SELL requires a green candle; HOLD is allowed on any candle.',
                ]);
            }
            $prepared[$decision] = ['row' => $row, 'action' => $action];
        }

        // Verify staged changes in bounded chronological batches before label
        // writes; retain IDs rather than thousands of hydrated chart payloads.
        $snapshotRows = [];
        foreach ($prepared as $decision => $change) {
            $snapshotRows[$decision] = $change['row'];
        }
        $snapshotIds = $this->snapshots->snapshotIdsForRows($manifest, array_values($snapshotRows));
        foreach ($snapshotRows as $decision => $_row) {
            if (($snapshotIds[$decision] ?? null) === null) {
                throw CandleTrainingRecovery::exception($trainer, $manifest, 'changes',
                    'A staged candle no longer matches the current source history and cannot be submitted.');
            }
        }

        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $saved = DB::transaction(function () use ($trainer, $prepared, $deleteAll, $marketKey, $snapshotIds): int {
            if ($deleteAll) {
                HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)
                    ->whereHas('snapshot', fn ($query) => $query->where('market_key', $marketKey)
                        ->where('version', HumanTraining::VERSION))
                    ->delete();
            } else {
                $deleteIds = [];
                foreach ($prepared as $decision => $change) {
                    if ($change['action'] === null) {
                        $deleteIds[] = $snapshotIds[$decision];
                    }
                }
                foreach (array_chunk($deleteIds, 500) as $chunk) {
                    HumanCandleLabel::query()->where('trainer_id', $trainer->user_id)
                        ->whereIn('snapshot_id', $chunk)->delete();
                }
            }

            $now = now()->format('Y-m-d H:i:s.v');
            $upserts = [];
            foreach ($prepared as $decision => $change) {
                if ($change['action'] === null) {
                    continue;
                }
                $snapshotId = $snapshotIds[$decision];
                $upserts[] = [
                    'candle_label_id' => (string) \Illuminate\Support\Str::uuid7(),
                    'snapshot_id' => $snapshotId,
                    'trainer_id' => $trainer->user_id,
                    'action' => $change['action'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($upserts, 500) as $chunk) {
                HumanCandleLabel::query()->upsert(
                    $chunk,
                    ['snapshot_id', 'trainer_id'],
                    ['action', 'updated_at']
                );
            }

            return count($upserts);
        });

        return ['saved' => $saved, 'deleted_all' => $deleteAll, 'stats' => $this->labelStats($trainer, $manifest)];
    }

    private function chartData(User $trainer, array $manifest, array $rows, array $series): array
    {
        $decisions = [];
        $allowedActions = [];
        $visibleTimes = array_flip(array_column($series, 'time'));
        $available = array_column($rows, null, 'microtimestamp');
        foreach ($series as $candle) {
            $allowedActions[(string) $candle['time']] = $this->allowedActions($candle);
            $microtimestamp = $candle['time'] * 1000;
            if (isset($available[$microtimestamp])) {
                $decisions[(string) $candle['time']] = $available[$microtimestamp]['decision_at_ms'];
            }
        }
        $decisionValues = array_values($decisions);
        $fromDecision = $decisionValues === [] ? 0 : min($decisionValues);
        $visibleLabels = HumanTrainingSnapshot::query()
            ->where('market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('version', HumanTraining::VERSION)
            ->whereBetween('decision_at_ms', [$fromDecision, $decisionValues === [] ? 0 : max($decisionValues)])
            ->whereHas('candleLabels', fn ($query) => $query->where('trainer_id', $trainer->user_id))
            ->with(['candleLabels' => fn ($query) => $query->where('trainer_id', $trainer->user_id)])
            ->orderBy('decision_at_ms')->get();
        $compatible = $this->snapshots->compatibleSnapshotIds($manifest, $visibleLabels, $rows);
        $visibleLabels = $visibleLabels->filter(fn (HumanTrainingSnapshot $snapshot): bool => isset($compatible[$snapshot->snapshot_id]))
            ->map(function (HumanTrainingSnapshot $item) use ($available, $manifest, $visibleTimes): ?array {
                $label = $item->candleLabels->first();
                if ($label === null || ! in_array($label->action, self::ACTIONS, true)) {
                    return null;
                }
                $itemPayload = $item->verifiedPayload();
                $source = $available[$itemPayload['microtimestamp']] ?? null;
                if (! isset($visibleTimes[intdiv($itemPayload['microtimestamp'], 1000)])
                    || $source === null || $itemPayload['decision_at_ms'] !== $source['decision_at_ms']
                    || ($itemPayload['feature_version'] ?? null) !== $manifest['feature_version']
                    || ($itemPayload['keys'] ?? null) !== $manifest['keys']
                    || ($itemPayload['normalization'] ?? null) !== NormalizedVector::VERSION
                    || ($itemPayload['horizon_candles'] ?? null) !== $manifest['label_definition']['horizon']
                    || ($itemPayload['vector'] ?? null) != NormalizedVector::from($source['vector'], $manifest['keys'])
                    || ($itemPayload['feature_sha256'] ?? null) !== ($source['source']['feature_sha256'] ?? null)) {
                    return null;
                }

                return ['time' => intdiv($itemPayload['microtimestamp'], 1000), 'action' => $label->action];
            })->filter()->unique('time')->values()->all();

        return ['labels' => $visibleLabels, 'decisions' => $decisions, 'allowed_actions' => $allowedActions];
    }

    /** @return list<string> */
    private function allowedActions(array $candle): array
    {
        $open = bcconv($candle['open']);
        $close = bcconv($candle['close']);
        $direction = bccomp($close, $open, max(2, bcdec([$open, $close])));

        return match ($direction) {
            -1 => ['buy', 'hold'],
            1 => ['hold', 'sell'],
            default => ['hold'],
        };
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

    /** @return array{row: array, snapshot: HumanTrainingSnapshot} */
    private function unseenSnapshot(User $trainer, array $manifest, array $rows): array
    {
        $candidate = $this->snapshots->candidateSnapshot($trainer, $manifest, $rows, 'candleLabels', allowReviewed: true);
        if ($candidate !== null) {
            return $candidate;
        }

        throw ValidationException::withMessages(['dataset' => 'No intact candle was found in this bounded search. Retry, choose another dataset or collect more history.']);
    }

    /** Counts this trainer's distinct recorded candle opinions, not model eligibility. */
    private function labelStats(User $trainer, array $manifest): array
    {
        $counts = array_fill_keys(self::ACTIONS, 0);
        // Count a trainer/candle once across revisions without loading chart payloads.
        // These are recorded-opinion milestones, not current KNN eligibility counts.
        $ranked = DB::table('human_candle_labels as labels')
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'labels.snapshot_id')
            ->where('labels.trainer_id', $trainer->user_id)
            ->where('snapshots.market_key', ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']))
            ->where('snapshots.version', HumanTraining::VERSION)->whereIn('labels.action', self::ACTIONS)
            ->select('labels.action')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY snapshots.decision_at_ms ORDER BY labels.updated_at DESC, labels.candle_label_id DESC) AS opinion_rank');
        $stored = DB::query()->fromSub($ranked, 'recorded_opinions')->where('opinion_rank', 1)
            ->selectRaw('action, COUNT(*) AS aggregate')->groupBy('action')->pluck('aggregate', 'action');
        foreach (self::ACTIONS as $action) {
            $counts[$action] = (int) ($stored[$action] ?? 0);
        }
        $total = array_sum($counts);
        $percentages = [];
        foreach ($counts as $action => $count) {
            $percentages[$action] = $total === 0 ? 0.0 : round($count * 100 / $total, 1);
        }
        $minimum = min($counts);

        return [
            'counts' => $counts,
            'percentages' => $percentages,
            'total' => $total,
            'recorded_distinct_candles' => $total,
            'sampling' => 'all_eligible_distinct_candles',
            // Kept as a legacy UI statistic, never used to cap training.
            'balanced_per_action' => $minimum,
            'balanced_samples' => $minimum * count(self::ACTIONS),
            'least_represented' => array_values(array_keys(array_filter($counts, fn (int $count): bool => $count === $minimum))),
        ];
    }

    /** Published CCXT spot taker fee, when the configured exchange exposes one. */
    private function takerFee(array $manifest): ?float
    {
        $matches = Exchange::query()->where('class', $manifest['exchange'])->limit(2)->get();
        if ($matches->count() !== 1) {
            return null;
        }
        try {
            foreach ($this->catalog->forExchange($matches->first())['symbols'] as $market) {
                if (($market['value'] ?? null) !== $manifest['symbol']) {
                    continue;
                }
                $fee = $market['taker_fee'] ?? null;

                return is_numeric($fee) && is_finite((float) $fee) && (float) $fee >= 0 ? (float) $fee : null;
            }
        } catch (Throwable) {
            // Training remains usable when the exchange metadata endpoint is temporarily unavailable.
        }

        return null;
    }
}
