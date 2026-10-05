<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\MarketSignal;
use App\Models\MarketFeature;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class HumanTraining
{
    public const VERSION = 'm4.4-human-snapshot-v1';

    public const LABELS = ['super_bull', 'bull', 'hold', 'bear', 'super_bear'];

    public function __construct(private DatasetStore $datasets, private TickerRepository $tickers, private CandleTimeframe $timeframe) {}

    /**
     * Return the newest eligible semantic dataset for each requested market.
     *
     * @param  array<int, array<string, mixed>>|null  $markets
     */
    public function datasets(?array $markets = null): array
    {
        $wanted = null;
        if ($markets !== null) {
            $wanted = [];
            foreach ($markets as $market) {
                $exchange = $market['exchange_class'] ?? $market['exchange'] ?? null;
                $symbol = $market['pair'] ?? $market['symbol'] ?? null;
                $period = $market['period'] ?? null;
                if (! is_string($exchange) || ! is_string($symbol) || ! is_string($period) || $period === '') {
                    continue;
                }
                $wanted[$this->datasetMarketKey($exchange, $symbol, $period)] = true;
            }
            if ($wanted === []) {
                return [];
            }
        }

        $latest = [];
        $nowMs = now()->getTimestampMs();
        foreach (DB::table('research_datasets')->orderByDesc('created_at')->orderByDesc('dataset_id')->cursor() as $record) {
            $manifest = json_decode($record->manifest, true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['feature_version'] ?? null) !== FeatureEngine::VERSION
                || ($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION
                || ($manifest['as_of_ms'] ?? PHP_INT_MAX) > $nowMs) {
                continue;
            }
            $exchange = $manifest['exchange'] ?? null;
            $symbol = $manifest['symbol'] ?? null;
            $period = $manifest['period'] ?? null;
            if (! is_string($exchange) || ! is_string($symbol) || ! is_string($period)) {
                continue;
            }
            $key = $this->datasetMarketKey($exchange, $symbol, $period);
            if ($wanted !== null && ! isset($wanted[$key])) {
                continue;
            }
            $latest[$key] ??= $manifest;
            if ($wanted !== null && count($latest) === count($wanted)) {
                break;
            }
        }

        return array_values($latest);
    }

    private function datasetMarketKey(string $exchange, string $symbol, string $period): string
    {
        return strtolower($exchange).'|'.$symbol.'|'.$period;
    }

    public function assign(User $trainer, string $dataset): HumanTrainingReview
    {
        Gate::forUser($trainer)->authorize('train-intelligence');

        return Cache::lock('trademinator:human-assignment:'.$trainer->user_id, 30)->block(3, function () use ($trainer, $dataset): HumanTrainingReview {
            $pending = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->whereNull('submitted_at')
                ->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->orderByDesc('shown_at')->first();
            if ($pending !== null) {
                return $pending;
            }
            [$manifest, $rows] = $this->datasets->load($dataset);
            if ($manifest['feature_version'] !== FeatureEngine::VERSION
                || ($manifest['label_definition']['version'] ?? null) !== SemanticLabels::VERSION
                || $manifest['as_of_ms'] > now()->getTimestampMs()
                || $rows === [] || count($rows) > config('intelligence.max_rows')) {
                throw ValidationException::withMessages(['dataset' => 'Choose a current, bounded semantic dataset built from closed candles.']);
            }
            $candidate = $this->candidateSnapshot($trainer, $manifest, $rows, 'reviews');
            if ($candidate !== null) {
                $review = new HumanTrainingReview;
                $review->forceFill(['snapshot_id' => $candidate['snapshot']->snapshot_id, 'trainer_id' => $trainer->user_id,
                    'shown_at' => now(), 'expires_at' => now()->addMinutes(config('human_training.assignment_minutes'))]);
                $review->save();

                return $review;
            }

            throw ValidationException::withMessages(['dataset' => 'No unseen, intact snapshot was found in this bounded search. Retry, choose another dataset or collect more history.']);
        });
    }

    /**
     * Select before hydrating charts. Query only distinct candle timestamps for
     * this trainer and the bounded dataset, then verify at most 24 candidates.
     * A previous revision's label is a hint, never proof that a row is reviewed.
     *
     * @return array{row: array, snapshot: HumanTrainingSnapshot}|null
     */
    public function candidateSnapshot(User $trainer, array $manifest, array $rows, string $relation,
        bool $allowReviewed = false): ?array
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        $table = match ($relation) {
            'candleLabels' => 'human_candle_labels',
            'reviews' => 'human_training_reviews',
            default => throw new InvalidArgumentException('Unsupported human opinion relation.'),
        };
        if (count($rows) > (int) config('intelligence.max_rows')) {
            throw new InvalidArgumentException('Candidate selection exceeds the dataset bound.');
        }
        $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
        $seen = [];
        $decisions = array_values(array_unique(array_map('intval', array_column($rows, 'decision_at_ms'))));
        foreach (array_chunk($decisions, 500) as $chunk) {
            $recorded = DB::table('human_training_snapshots as snapshots')
                ->where('snapshots.market_key', $marketKey)->where('snapshots.version', self::VERSION)
                ->whereIn('snapshots.decision_at_ms', $chunk)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from($table.' as opinions')
                    ->whereColumn('opinions.snapshot_id', 'snapshots.snapshot_id')
                    ->where('opinions.trainer_id', $trainer->user_id))
                ->distinct()->pluck('snapshots.decision_at_ms');
            foreach ($recorded as $decision) {
                $seen[(int) $decision] = true;
            }
        }

        return (new TrainingCandidateSelector)->select($rows, $seen,
            fn (array $row): ?HumanTrainingSnapshot => $this->snapshotForRow($manifest, $row),
            fn (HumanTrainingSnapshot $snapshot): bool => DB::table($table)
                ->where('snapshot_id', $snapshot->snapshot_id)->where('trainer_id', $trainer->user_id)->exists(),
            (int) config('human_training.candidate_attempts'), $allowReviewed);
    }

    public function snapshotForRow(array $manifest, array $row): ?HumanTrainingSnapshot
    {
        return $this->snapshotsForRows($manifest, [$row])[(int) $row['decision_at_ms']] ?? null;
    }

    /** Bounded chronological batches; old snapshots and labels remain immutable. */
    public function snapshotsForRows(array $manifest, array $rows): array
    {
        $rows = TrainingRowAudit::inspect($rows)['rows'];
        if ($rows === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($rows, 50) as $batch) {
            $requested = array_column($batch, null, 'decision_at_ms');
            $result += app(SnapshotRevisions::class)->resolve($manifest, $this->batchSnapshotPayloads($manifest, $requested));
        }

        return $result;
    }

    /** Resolve large submissions without retaining thousands of hydrated charts. */
    public function snapshotIdsForRows(array $manifest, array $rows): array
    {
        $ids = [];
        foreach (array_chunk(TrainingRowAudit::inspect($rows)['rows'], 50) as $batch) {
            foreach ($this->snapshotsForRows($manifest, $batch) as $decision => $snapshot) {
                $ids[$decision] = $snapshot?->snapshot_id;
            }
        }

        return $ids;
    }

    /**
     * Source repairs also invalidate reviewed chart context, even if selected
     * feature values happen to be unchanged. Read raw immutable dataset vectors
     * here: the trainer's downstream rows may already be normalized/augmented.
     * Source-less legacy records without a known history revision retain their
     * prior caller checks. Provenance-bearing and new snapshots are verified.
     *
     * @return array<string, true> compatible snapshot IDs
     */
    public function compatibleSnapshotIds(array $manifest, iterable $snapshots, ?array $rawRows = null): array
    {
        $snapshots = collect($snapshots);
        if ($snapshots->isEmpty()) {
            return [];
        }
        $legacyIds = [];
        $strictTimes = [];
        $legacyHistory = app(SnapshotRevisions::class)->historyRevision($manifest) === 0;
        foreach ($snapshots as $snapshot) {
            $payload = $snapshot->verifiedPayload();
            if ($legacyHistory && ! isset($payload['revision']) && ($payload['feature_sha256'] ?? null) === null) {
                // Preserve the previous compatibility checks for source-less legacy
                // research records only; a real provenance-bearing record is strict.
                $legacyIds[$snapshot->snapshot_id] = (int) $snapshot->decision_at_ms;
            } else {
                $strictTimes[(int) $snapshot->decision_at_ms] = true;
            }
        }
        $ids = [];
        foreach ($legacyIds as $id => $decision) {
            if (! isset($strictTimes[$decision])) {
                $ids[$id] = true;
            }
        }
        if ($strictTimes === []) {
            return $ids;
        }
        $rawRows ??= $this->datasets->load($manifest['dataset_id'], (int) config('intelligence.max_rows'))[1];
        $rows = array_filter($rawRows, fn (array $row): bool => isset($strictTimes[$row['decision_at_ms']]));
        $current = $this->snapshotIdsForRows($manifest, array_values($rows));
        foreach ($current as $snapshotId) {
            if ($snapshotId !== null) {
                $ids[$snapshotId] = true;
            }
        }

        return $ids;
    }

    private function snapshotMatchesRow(HumanTrainingSnapshot $snapshot, array $manifest, array $row): bool
    {
        $payload = $snapshot->verifiedPayload();
        $vector = NormalizedVector::from($row['vector'], $manifest['keys']);

        return ($payload['feature_version'] ?? null) === $manifest['feature_version']
            && ($payload['keys'] ?? null) === $manifest['keys']
            && ($payload['normalization'] ?? null) === NormalizedVector::VERSION
            && ($payload['horizon_candles'] ?? null) === $manifest['label_definition']['horizon']
            && ($payload['vector'] ?? null) == $vector
            && ($payload['feature_sha256'] ?? null) === ($row['source']['feature_sha256'] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows keyed by decision_at_ms
     * @return array<int, array<string, mixed>|null>
     */
    private function batchSnapshotPayloads(array $manifest, array $rows): array
    {
        uasort($rows, fn (array $a, array $b): int => ((int) $a['microtimestamp']) <=> ((int) $b['microtimestamp']));
        $first = reset($rows);
        $last = end($rows);
        if (! is_array($first) || ! is_array($last)) {
            return [];
        }

        $period = $manifest['period'];
        $from = (int) $first['microtimestamp'];
        for ($i = 1; $i < config('human_training.chart_candles'); $i++) {
            $from = max(0, $this->timeframe->previous($from, $period));
        }
        $to = (int) $last['microtimestamp'];

        $history = [];
        $historyTimes = [];
        $historyIndex = [];
        foreach ($this->tickers->streamHistory($manifest['exchange'], $manifest['symbol'], $period, $from, $to) as $bar) {
            $index = count($history);
            $timestamp = (int) ($bar['microtimestamp'] ?? 0);
            $history[] = $bar;
            $historyTimes[] = $timestamp;
            $historyIndex[$timestamp] = $index;
        }

        // Do not combine an old dataset vector with a newly repaired chart.
        // Match the digest contract used by DatasetSnapshotBuilder exactly.
        $featureDigests = [];
        $sourceTimes = array_column(array_filter($rows, fn (array $row): bool => isset($row['source']['feature_sha256'])), 'microtimestamp');
        foreach (array_chunk($sourceTimes, 500) as $chunk) {
            foreach (MarketFeature::query()->where('exchange', $manifest['exchange'])->where('symbol', $manifest['symbol'])
                ->where('period', $period)->where('version', $manifest['feature_version'])
                ->whereIn('microtimestamp', $chunk)->get() as $feature) {
                $featureDigests[(int) $feature->microtimestamp] = hash('sha256', json_encode($feature->payload, JSON_THROW_ON_ERROR));
            }
        }
        $observations = $this->batchModelObservations($manifest, array_keys($rows));
        $payloads = [];
        foreach ($rows as $decision => $row) {
            $timestamp = (int) $row['microtimestamp'];
            $end = $historyIndex[$timestamp] ?? null;
            $expectedFeatureDigest = $row['source']['feature_sha256'] ?? null;
            if ($end === null || ($expectedFeatureDigest !== null
                && $expectedFeatureDigest !== ($featureDigests[$timestamp] ?? null))) {
                $payloads[$decision] = null;
                continue;
            }

            $windowFrom = $timestamp;
            for ($i = 1; $i < config('human_training.chart_candles'); $i++) {
                $windowFrom = max(0, $this->timeframe->previous($windowFrom, $period));
            }
            $start = $this->historyLowerBound($historyTimes, $windowFrom);
            $bars = array_slice($history, $start, $end - $start + 1);
            $payloads[$decision] = $this->batchSnapshotPayload(
                $manifest,
                $row,
                $bars,
                $observations[$decision] ?? null
            );
        }

        return $payloads;
    }

    /**
     * One indexed, limited lookup per cutoff, never a hydration of all historical
     * signals. Both timestamps must be known by the decision. Preserve the
     * recorded-time / UUID tie-break used by the original replay contract.
     *
     * @param  list<int>  $decisions
     * @return array<int, array<string, mixed>|null>
     */
    private function batchModelObservations(array $manifest, array $decisions): array
    {
        $decisions = array_values(array_unique(array_map('intval', $decisions)));
        sort($decisions, SORT_NUMERIC);
        if ($decisions === []) {
            return [];
        }
        $marketIds = DB::table('markets')->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $manifest['exchange'])->where('markets.symbol', $manifest['symbol'])
            ->pluck('markets.market_id')->all();
        $result = array_fill_keys($decisions, null);
        if ($marketIds === []) {
            return $result;
        }
        $columns = ['market_signal_id', 'model_id', 'recorded_at_ms', 'decision_at_ms', 'action', 'reason'];
        foreach ($decisions as $decision) {
            $signal = MarketSignal::query()->whereIn('market_id', $marketIds)->where('period', $manifest['period'])
                ->where('recorded_at_ms', '<=', $decision)->where('decision_at_ms', '<=', $decision)
                ->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->first($columns);
            if ($signal !== null) {
                $result[$decision] = [
                    'model_id' => $signal->model_id,
                    'recorded_at_ms' => (int) $signal->recorded_at_ms,
                    'decision_at_ms' => (int) $signal->decision_at_ms,
                    'action' => $signal->action, 'reason' => $signal->reason,
                ];
            }
        }

        return $result;
    }

    /**
     * Build the immutable snapshot payload from an already loaded history window.
     *
     * @param  array<int, array<string, mixed>>  $bars
     */
    private function batchSnapshotPayload(array $manifest, array $row, array $bars, ?array $modelObservation): ?array
    {
        $decision = (int) $row['decision_at_ms'];
        $timestamp = (int) $row['microtimestamp'];
        $period = $manifest['period'];
        if ($decision !== $this->timeframe->next($timestamp, $period) || $decision > now()->getTimestampMs()) {
            return null;
        }

        $series = [];
        $previous = null;
        $gaps = 0;
        foreach ($bars as $bar) {
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field])) {
                    return null;
                }
            }
            if (min($bar['open'], $bar['low'], $bar['close']) <= 0 || $bar['volume'] < 0
                || $bar['high'] < max($bar['open'], $bar['close'], $bar['low'])
                || $bar['low'] > min($bar['open'], $bar['close'])
                || $this->timeframe->next((int) $bar['microtimestamp'], $period) > $decision) {
                return null;
            }
            if ($previous !== null && $this->timeframe->next($previous, $period) !== (int) $bar['microtimestamp']) {
                $gaps++;
            }
            $previous = (int) $bar['microtimestamp'];
            $series[] = ['time' => intdiv((int) $bar['microtimestamp'], 1000),
                ...array_map('strval', array_intersect_key($bar, array_flip(['open', 'high', 'low', 'close', 'volume'])))];
            if ((int) $bar['microtimestamp'] === $timestamp && isset($row['candle'])) {
                foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                    if ((string) $bar[$field] !== (string) $row['candle'][$field]) {
                        return null;
                    }
                }
            }
        }
        if ($previous !== $timestamp || count($series) < 2) {
            return null;
        }

        $vector = NormalizedVector::from($row['vector'], $manifest['keys']);

        return ['version' => self::VERSION, 'feature_version' => $manifest['feature_version'],
            'normalization' => NormalizedVector::VERSION, 'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
            'period' => $period, 'microtimestamp' => $timestamp, 'decision_at_ms' => $decision,
            'horizon_candles' => $manifest['label_definition']['horizon'],
            'keys' => $manifest['keys'], 'vector' => $vector,
            'features' => array_combine($manifest['keys'], $row['vector']),
            'feature_sha256' => $row['source']['feature_sha256'] ?? null,
            'series' => $series, 'gaps' => $gaps,
            'patterns' => array_map(fn (array $pattern): array => array_intersect_key($pattern,
                array_flip(['type', 'length', 'stage', 'progress', 'similarity'])), $row['patterns'] ?? []),
            'model_observation' => $modelObservation];
    }

    /** Return the first history index whose timestamp is >= the requested time. */
    private function historyLowerBound(array $timestamps, int $wanted): int
    {
        $low = 0;
        $high = count($timestamps);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ((int) $timestamps[$mid] < $wanted) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    private function snapshot(array $manifest, array $row): ?array
    {
        $decision = $row['decision_at_ms'];
        $timestamp = $row['microtimestamp'];
        $period = $manifest['period'];
        if ($decision !== $this->timeframe->next($timestamp, $period) || $decision > now()->getTimestampMs()) {
            return null;
        }
        $from = $timestamp;
        for ($i = 1; $i < config('human_training.chart_candles'); $i++) {
            $from = max(0, $this->timeframe->previous($from, $period));
        }
        $series = [];
        $previous = null;
        $gaps = 0;
        foreach ($this->tickers->streamHistory($manifest['exchange'], $manifest['symbol'], $period, $from, $timestamp) as $bar) {
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field])) {
                    return null;
                }
            }
            if (min($bar['open'], $bar['low'], $bar['close']) <= 0 || $bar['volume'] < 0
                || $bar['high'] < max($bar['open'], $bar['close'], $bar['low'])
                || $bar['low'] > min($bar['open'], $bar['close'])
                || $this->timeframe->next($bar['microtimestamp'], $period) > $decision) {
                return null;
            }
            if ($previous !== null && $this->timeframe->next($previous, $period) !== $bar['microtimestamp']) {
                $gaps++;
            }
            $previous = $bar['microtimestamp'];
            $series[] = ['time' => intdiv($bar['microtimestamp'], 1000),
                ...array_map('strval', array_intersect_key($bar, array_flip(['open', 'high', 'low', 'close', 'volume'])))];
            if ($bar['microtimestamp'] === $timestamp && isset($row['candle'])) {
                foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                    if ((string) $bar[$field] !== (string) $row['candle'][$field]) {
                        return null;
                    }
                }
            }
        }
        if ($previous !== $timestamp || count($series) < 2) {
            return null;
        }
        $vector = NormalizedVector::from($row['vector'], $manifest['keys']);
        $signal = MarketSignal::query()->whereHas('market', fn ($query) => $query->where('symbol', $manifest['symbol'])
            ->whereHas('exchange', fn ($query) => $query->where('class', $manifest['exchange'])))
            ->where('period', $period)->where('recorded_at_ms', '<=', $decision)->where('decision_at_ms', '<=', $decision)
            ->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->first();

        return ['version' => self::VERSION, 'feature_version' => $manifest['feature_version'],
            'normalization' => NormalizedVector::VERSION, 'exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
            'period' => $period, 'microtimestamp' => $timestamp, 'decision_at_ms' => $decision,
            'horizon_candles' => $manifest['label_definition']['horizon'],
            'keys' => $manifest['keys'], 'vector' => $vector,
            'features' => array_combine($manifest['keys'], $row['vector']),
            'feature_sha256' => $row['source']['feature_sha256'] ?? null,
            'series' => $series, 'gaps' => $gaps,
            'patterns' => array_map(fn (array $pattern): array => array_intersect_key($pattern,
                array_flip(['type', 'length', 'stage', 'progress', 'similarity'])), $row['patterns'] ?? []),
            'model_observation' => $signal === null ? null : ['model_id' => $signal->model_id,
                'recorded_at_ms' => $signal->recorded_at_ms, 'decision_at_ms' => $signal->decision_at_ms,
                'action' => $signal->action, 'reason' => $signal->reason]];
    }

    public function submit(User $trainer, string $id, string $label, ?int $confidence, ?string $reason): HumanTrainingReview
    {
        Gate::forUser($trainer)->authorize('train-intelligence');
        if (! in_array($label, [...self::LABELS, 'skip'], true) || ($confidence !== null && ($confidence < 0 || $confidence > 100))
            || ($reason !== null && mb_strlen($reason) > 2000)) {
            throw ValidationException::withMessages(['label' => 'Choose a valid review label and confidence from 0 to 100.']);
        }

        return DB::transaction(function () use ($trainer, $id, $label, $confidence, $reason): HumanTrainingReview {
            $review = HumanTrainingReview::query()->where('trainer_id', $trainer->user_id)->lockForUpdate()->findOrFail($id);
            abort_if($review->submitted_at !== null || ! $review->expires_at->isFuture(), 409, 'This review was already submitted or has expired. Request another snapshot.');
            $review->snapshot->verifiedPayload();
            $review->forceFill(['label' => $label, 'confidence' => $confidence, 'reason' => $reason, 'submitted_at' => now()])->save();

            return $review;
        });
    }

    public function display(HumanTrainingReview $review): array
    {
        $payload = $review->snapshot->verifiedPayload();
        if ($review->submitted_at === null) {
            unset($payload['model_observation']);
        }

        return $payload;
    }
}
