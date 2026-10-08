<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Domain\Research\DatasetSnapshotBuilder;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Models\MarketFeature;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class MarketIntelligence
{
    public function __construct(
        private DatasetSnapshotBuilder $datasets,
        private DatasetStore $datasetStore,
        private IntelligenceTrainer $trainer,
        private ModelStore $models,
        private PatternCatalog $catalog,
        private PatternTrainer $patterns,
        private LeadLagIntelligence $leadLag,
        private HumanCandleKnn $candleKnn,
        private HumanGuidance $humanOutcome,
        private TickerRepository $tickers,
        private SignalFreshness $freshness,
    ) {}

    public function build(string $exchange, string $symbol, string $period, ?string $dataset = null,
        string $schema = 'core', ?int $fromMs = null, ?int $toMs = null, ?int $asOfMs = null,
        ?string $generation = null, array $buildPerformance = [], ?string $featureLockOwner = null, ?string $contextFallback = null): array
    {
        $fallback = $contextFallback ?? config('intelligence.context_fallback', 'none');
        if (! in_array($fallback, ['none', 'technical'], true)) {
            throw new InvalidArgumentException('Context fallback must be none or technical.');
        }
        if ($contextFallback !== null && ($dataset !== null || ($fallback === 'technical' && $schema !== 'full'))) {
            throw new InvalidArgumentException('Technical context fallback requires a new full-schema build.');
        }
        // A previous strict-full generation must not suppress an opted-in build.
        if ($generation !== null && $dataset === null && $schema === 'full' && $fallback === 'technical') {
            $generation = hash('sha256', $generation.'|'.AutomaticSchemaSelection::VERSION);
        }
        $schemaSelection = [];
        $buildPerformance['started_at'] ??= now()->toIso8601String();
        $buildPerformance['started_monotonic_ns'] = hrtime(true);
        $buildPerformance['stages'] ??= [];
        $deadline = microtime(true) + config('intelligence.max_seconds');
        $lock = Cache::lock('trademinator:intelligence-build:'.ModelStore::marketKey($exchange, $symbol, $period), 1020);
        if (! $lock->get()) {
            throw new RuntimeException('A knowledge build is already running for this market and period.');
        }
        try {
            if ($generation !== null && ($existing = $this->models->generation($generation)) !== null) {
                return $existing;
            }
            $datasetStarted = hrtime(true);
            if ($dataset !== null) {
                $manifest = $this->datasetStore->manifest($dataset);
                if ([$manifest['exchange'], $manifest['symbol'], $manifest['period']] !== [$exchange, $symbol, $period]) {
                    throw new InvalidArgumentException('Dataset market and period do not match the requested model.');
                }
            } else {
                $query = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)
                    ->where('period', $period)->where('version', FeatureEngine::VERSION)
                    ->where('available_at_ms', '<=', now()->getTimestampMs());
                $latest = (clone $query)->orderByDesc('microtimestamp')->first();
                if ($latest === null) {
                    throw new RuntimeException('No current M2 features; run trademinator:build-features first.');
                }
                $asOfMs = min($asOfMs ?? $latest->microtimestamp, now()->getTimestampMs());
                $toMs = min($toMs ?? $asOfMs, $asOfMs);
                $fromMs = max($fromMs ?? 0, KnowledgeWindow::fromMs($asOfMs));
                // H is only a seed here. DatasetSnapshotBuilder replaces it with the
                // frequency-weighted Action-pivot horizon before labeling rows.
                $definition = new SemanticLabels(config('intelligence.horizon'), config('intelligence.lookback'));
                [$manifest, $schemaSelection] = $this->snapshotWithSchemaPolicy($exchange, $symbol, $period,
                    $definition, $schema, (int) $fromMs, $toMs, $asOfMs, $featureLockOwner, $fallback, $deadline);
                $dataset = $manifest['dataset_id'];
            }

            $buildPerformance['stages']['dataset_ms'] = (int) ($buildPerformance['stages']['dataset_ms'] ?? 0)
                + (int) round((hrtime(true) - $datasetStarted) / 1_000_000);

            return $this->trainer->train($dataset, $deadline, $generation, $buildPerformance, $schemaSelection);
        } finally {
            $lock->release();
        }
    }

    /** Freeze one schema before any model fitting; never retry after validation failure. */
    private function snapshotWithSchemaPolicy(string $exchange, string $symbol, string $period, SemanticLabels $definition,
        string $schema, int $fromMs, int $toMs, int $asOfMs, ?string $featureLockOwner, string $fallback, float $deadline): array
    {
        $selection = ['requested_schema' => $schema, 'effective_schema' => $schema,
            'fallback_policy' => $schema === 'full' ? $fallback : 'none', 'reason' => 'explicit_schema'];
        $ownedLock = null;
        try {
            if ($schema === 'full' && $fallback === 'technical') {
                $lockName = 'trademinator:features:'.hash('sha256', "$exchange|$symbol|$period");
                if ($featureLockOwner === null) {
                    $ownedLock = Cache::lock($lockName, 720);
                    if (! $ownedLock->get()) {
                        throw new RuntimeException('Features or a dataset are already being built for this market and period.');
                    }
                    $featureLockOwner = $ownedLock->owner();
                } elseif (! Cache::restoreLock($lockName, $featureLockOwner)->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Feature lock ownership was lost before schema inspection.');
                }
                $selection = app(AutomaticSchemaSelection::class)->inspect($exchange, $symbol, $period,
                    $fromMs, $toMs, $asOfMs, $definition->horizon, $deadline);
                if (($selection['reason'] ?? null) === 'insufficient_both_feature_histories') {
                    throw new IntelligenceNotReady($selection);
                }
                $schema = $selection['effective_schema'];
            }
            $manifest = $this->datasets->build($exchange, $symbol, $period, $definition, $schema,
                fromMs: $fromMs, toMs: $toMs, asOfMs: $asOfMs, featureLockOwner: $featureLockOwner);

            return [$manifest, $selection];
        } finally {
            $ownedLock?->release();
        }
    }

    public function predict(string $exchange, string $symbol, string $period, ?int $asOfMs = null): array
    {
        $result = $this->evaluate($exchange, $symbol, $period, $asOfMs);
        $result['action_meaning'] = SignalSemantics::actionMeaning($result['action'], $result['reason'], $result['scoring'] ?? null);
        app(ActionLog::class)->write('intelligence.predicted', [
            'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period, 'model_id' => $result['model_id'] ?? null,
            'action' => $result['action'], 'reason' => $result['reason'], 'confidence' => $result['confidence'],
            'effective_neighbors' => $result['effective_neighbors'], 'outcome' => 'completed']);

        return $result;
    }

    private function evaluate(string $exchange, string $symbol, string $period, ?int $asOfMs): array
    {
        $asOfMs = min($asOfMs ?? now()->getTimestampMs(), now()->getTimestampMs());
        $model = $this->models->currentForPrediction($exchange, $symbol, $period);
        $context = ['exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
            'horizon_candles' => $model['label_definition']['horizon'] ?? null,
            'model_id' => $model['model_id'] ?? null, 'regime' => 'neutral', 'lead_lag' => [], 'patterns' => [], 'patterns_evaluated' => false];
        if ($model === null) {
            return [...WeightedKnn::abstain('no_model'), ...$context];
        }
        if (($model['validation_version'] ?? null) !== IntelligenceTrainer::VERSION
            || $model['feature_version'] !== FeatureEngine::VERSION || $model['normalization'] !== NormalizedVector::VERSION
            || $model['patterns']['version'] !== PatternCatalog::VERSION) {
            return [...WeightedKnn::abstain('model_version_mismatch'), ...$context];
        }
        if ($model['trained_as_of_ms'] < KnowledgeWindow::fromMs($asOfMs)) {
            return [...WeightedKnn::abstain('stale_model'), ...$context];
        }
        $features = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('version', FeatureEngine::VERSION)->where('available_at_ms', '<=', $asOfMs)
            ->orderByDesc('microtimestamp')->limit(2)->get()->reverse()->values();
        if ($features->isEmpty()) {
            return [...WeightedKnn::abstain('missing_features'), ...$context];
        }
        $current = $features->last();
        $timeframe = new CandleTimeframe;
        $context['decision_at_ms'] = $current->available_at_ms;
        $staleAt = $this->freshness->expiresAt($current->available_at_ms, $period);
        if ($staleAt === null || $asOfMs >= $staleAt) {
            return [...WeightedKnn::abstain('stale_features'), ...$context];
        }
        if ($current->available_at_ms <= $model['available_at_ms']) {
            return [...WeightedKnn::abstain('no_post_training_candle'), ...$context];
        }
        $wantedTimestamps = $features->pluck('microtimestamp')->map(fn ($value): int => (int) $value)->all();
        $wanted = array_fill_keys($wantedTimestamps, true);
        $tickers = [];
        foreach ($this->tickers->streamHistory(
            $exchange, $symbol, $period, min($wantedTimestamps), max($wantedTimestamps)
        ) as $timestamp => $candle) {
            if (isset($wanted[$timestamp])) {
                $tickers[$timestamp] = $candle;
            }
        }

        $history = [];
        foreach ($features as $feature) {
            $payload = $feature->payload;
            $candle = $tickers[(int) $feature->microtimestamp] ?? null;
            if ($candle === null || ($payload['version'] ?? null) !== FeatureEngine::VERSION
                || ($payload['microtimestamp'] ?? null) !== $feature->microtimestamp
                || ($payload['available_at_ms'] ?? null) !== $timeframe->next($feature->microtimestamp, $period)
                || $feature->available_at_ms !== $payload['available_at_ms']
                || (float) ($candle['close'] ?? 0) !== (float) ($payload['close'] ?? -1)) {
                return [...WeightedKnn::abstain('source_feature_mismatch'), ...$context];
            }
            $candle['microtimestamp'] = $feature->microtimestamp;
            $history[] = ['features' => $payload['features'], 'candle' => $candle];
        }
        $currentCandle = $tickers[(int) $current->microtimestamp];
        $context['reference_price'] = (string) $currentCandle['close'];
        $context['reference_price_source'] = 'closed_candle_close';

        $prepared = $this->predictionVector($model, $current->payload, $history, $current->available_at_ms, $context);
        $preparedError = $prepared['error'] ?? null;

        $settings = $model['settings'];
        $sourceFusion = new TrainingSourceFusion((float) $settings['min_confidence']);
        $neighborKnn = new WeightedKnn(
            $settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']
        );

        $outcomeAlgorithmic = OutcomeKnn::abstain(
            $preparedError ?? ($model['outcome']['algorithmic']['reason'] ?? 'outcome_model_unavailable')
        );
        $outcomeK = $model['outcome']['algorithmic']['k'] ?? null;
        if ($preparedError === null && ($model['outcome']['algorithmic']['status'] ?? null) === 'ready'
            && is_int($outcomeK) && $outcomeK > 0) {
            $neighbors = $neighborKnn->neighborsIterable(
                $this->models->knowledge($model), $prepared['vector'], $outcomeK,
                $current->available_at_ms, $prepared['weights']
            );
            $outcomeAlgorithmic = (new OutcomeKnn($settings))->vote($neighbors, $outcomeK);
        }
        $outcomeHuman = $this->humanOutcome->predict(
            $model['outcome']['human'] ?? [], $current->payload, $current->available_at_ms,
            $this->models->humanKnowledge($model, 'outcome')
        );
        $outcome = $sourceFusion->combine(
            $outcomeAlgorithmic, $outcomeHuman, SemanticLabels::OUTCOMES, 'outcome',
            (int) ($model['outcome']['human']['samples'] ?? 0)
        );

        $actionAlgorithmic = WeightedKnn::abstain(
            $preparedError ?? ($model['action']['algorithmic']['reason'] ?? 'action_model_unavailable')
        );
        $actionK = $model['action']['algorithmic']['k'] ?? null;
        if ($preparedError === null && ($model['action']['algorithmic']['status'] ?? null) === 'ready'
            && is_int($actionK) && $actionK > 0) {
            $actionAlgorithmic = $neighborKnn->predictIterable(
                $this->actionKnowledge($this->models->knowledge($model)), $prepared['vector'], $actionK,
                $current->available_at_ms, $prepared['weights']
            );
        }
        $actionHuman = $this->candleKnn->predict(
            $model['action']['human'] ?? [], $current->payload, $current->available_at_ms,
            $this->models->humanKnowledge($model, 'action')
        );
        $action = $sourceFusion->combine(
            $actionAlgorithmic, $actionHuman, ['buy', 'hodl', 'sell'], 'action',
            (int) ($model['action']['human']['samples'] ?? 0)
        );

        $decision = SignalDecisionMatrix::resolve($action, $outcome);
        $context['regime'] = $outcome['reason'] === 'supported' ? $outcome['outcome'] : 'neutral';
        $context['outcome_knn'] = $outcome;
        $context['action_knn'] = $action;

        return [
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'reason' => $decision['reason'],
            'neighbors' => $decision['neighbors'],
            'effective_neighbors' => $decision['effective_neighbors'],
            'similarity' => $decision['similarity'],
            'votes' => $action['votes'],
            'scoring' => [
                'version' => 'outcome-action-matrix-v2',
                'decision_mode' => $decision['mode'],
                'outcome' => $outcome,
                'action' => $action,
                'resolved_action' => $decision['action'] === 'hodl' ? 'hold' : $decision['action'],
            ],
            ...$context,
            'k' => $outcomeK,
            'action_k' => $actionK,
        ];
    }

    /** @return array{vector?: array, weights?: array, error?: string} */
    private function predictionVector(array $model, array $payload, array $history, int $decisionAt, array &$context): array
    {
        $vector = FeatureSchema::vector($payload, $model['keys']);
        if ($vector === null) {
            return ['error' => 'missing_selected_features'];
        }
        $vector = NormalizedVector::from($vector, $model['keys']);
        $context['patterns_evaluated'] = true;
        $context['patterns'] = $this->patterns->predict(
            $model['patterns'], $vector, $this->catalog->candidates($history, $model['period']), $decisionAt
        );
        $weights = [];
        if (($model['lead_lag_keys'] ?? []) !== []) {
            if (($model['lead_lag']['version'] ?? null) !== LeadLagTrainer::VERSION) {
                return ['error' => 'model_version_mismatch'];
            }
            $leadLag = $this->leadLag->current($model['lead_lag'], $decisionAt);
            $weights = [...array_fill(0, count($vector), 1.0), ...$leadLag['weights']];
            $vector = [...$vector, ...$leadLag['vector']];
            $context['lead_lag'] = $leadLag['signals'];
        }

        return compact('vector', 'weights');
    }

    private function actionKnowledge(iterable $rows): iterable
    {
        foreach ($rows as $row) {
            if (! in_array($row['action_label'] ?? null, ['buy', 'hodl', 'sell'], true)) {
                continue;
            }
            $row['label'] = $row['action_label'];
            yield $row;
        }
    }
}
