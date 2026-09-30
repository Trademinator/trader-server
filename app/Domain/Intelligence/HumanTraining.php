<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\SemanticLabels;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use App\Models\MarketSignal;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class HumanTraining
{
    public const VERSION = 'm4.4-human-snapshot-v1';

    public const LABELS = ['super_bull', 'bull', 'hold', 'bear', 'super_bear'];

    public function __construct(private DatasetStore $datasets, private TickerRepository $tickers, private CandleTimeframe $timeframe) {}

    public function datasets(): array
    {
        return DB::table('research_datasets')->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (object $record): array => json_decode($record->manifest, true, flags: JSON_THROW_ON_ERROR))
            ->filter(fn (array $manifest): bool => ($manifest['feature_version'] ?? null) === FeatureEngine::VERSION
                && ($manifest['label_definition']['version'] ?? null) === SemanticLabels::VERSION
                && ($manifest['as_of_ms'] ?? PHP_INT_MAX) <= now()->getTimestampMs())
            ->values()->all();
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
            $marketKey = ModelStore::marketKey($manifest['exchange'], $manifest['symbol'], $manifest['period']);
            $seen = HumanTrainingSnapshot::query()->where('market_key', $marketKey)
                ->whereBetween('decision_at_ms', [min(array_column($rows, 'decision_at_ms')), max(array_column($rows, 'decision_at_ms'))])
                ->whereHas('reviews', fn ($query) => $query->where('trainer_id', $trainer->user_id))
                ->pluck('decision_at_ms')->flip();
            $indices = array_keys(array_filter($rows, fn (array $row): bool => ! $seen->has($row['decision_at_ms'])));
            shuffle($indices);
            foreach (array_slice($indices, 0, config('human_training.candidate_attempts')) as $index) {
                $row = $rows[$index];
                $key = hash('sha256', $marketKey.'|'.$row['decision_at_ms'].'|'.self::VERSION);
                $snapshot = HumanTrainingSnapshot::query()->where('snapshot_key', $key)->first();
                if ($snapshot === null) {
                    $payload = $this->snapshot($manifest, $row);
                    if ($payload === null) {
                        continue;
                    }
                    $snapshot = HumanTrainingSnapshot::unguarded(fn (): HumanTrainingSnapshot => HumanTrainingSnapshot::query()->firstOrCreate(
                        ['snapshot_key' => $key], ['market_key' => $marketKey,
                            'dataset_id' => $manifest['dataset_id'], 'decision_at_ms' => $row['decision_at_ms'],
                            'version' => self::VERSION, 'payload' => $payload,
                            'sha256' => HumanTrainingSnapshot::digest($payload), 'created_at' => now()]));
                }
                $review = new HumanTrainingReview;
                $review->forceFill(['snapshot_id' => $snapshot->snapshot_id, 'trainer_id' => $trainer->user_id,
                    'shown_at' => now(), 'expires_at' => now()->addMinutes(config('human_training.assignment_minutes'))]);
                $review->save();

                return $review;
            }

            throw ValidationException::withMessages(['dataset' => 'No unseen, intact snapshot was found in this bounded search. Retry, choose another dataset or collect more history.']);
        });
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
