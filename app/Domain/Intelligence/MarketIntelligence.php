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
        private KnnEnsemble $ensemble,
        private TickerRepository $tickers,
        private SignalFreshness $freshness,
    ) {}

    public function build(string $exchange, string $symbol, string $period, ?string $dataset = null,
        string $schema = 'core', ?int $fromMs = null, ?int $toMs = null, ?int $asOfMs = null,
        ?string $generation = null, array $buildPerformance = [], ?string $featureLockOwner = null): array
    {
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
                $definition = new SemanticLabels(config('intelligence.horizon'), config('intelligence.lookback'),
                    config('intelligence.minimum_move_bps'), config('intelligence.extreme_fraction'));
                $manifest = $this->datasets->build($exchange, $symbol, $period, $definition, $schema,
                    fromMs: (int) $fromMs, toMs: $toMs, asOfMs: $asOfMs, featureLockOwner: $featureLockOwner);
                $dataset = $manifest['dataset_id'];
            }

            $buildPerformance['stages']['dataset_ms'] = (int) ($buildPerformance['stages']['dataset_ms'] ?? 0)
                + (int) round((hrtime(true) - $datasetStarted) / 1_000_000);

            return $this->trainer->train($dataset, $deadline, $generation, $buildPerformance);
        } finally {
            $lock->release();
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
        $model = $this->models->current($exchange, $symbol, $period);
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
        $automatic = $this->automaticPrediction($model, $current->payload, $history, $current->available_at_ms, $context);
        $human = $this->candleKnn->predict($model['candle_guidance'], $current->payload, $current->available_at_ms);
        $result = $this->ensemble->combine($automatic, $human, $model['ensemble']);
        $result['scoring']['components']['automatic']['input_keys'] = [...$model['keys'], ...$model['pattern_keys'], ...($model['lead_lag_keys'] ?? [])];
        $result['scoring']['components']['automatic']['context_keys'] = array_values(array_diff($model['keys'], FeatureEngine::KEYS));
        $result['scoring']['components']['human_candle']['input_keys'] = $model['candle_guidance']['input_keys'] ?? [];
        $context['candle_guidance'] = ['status' => $model['candle_guidance']['status'], 'mode' => 'independent_knn',
            'action_shares' => $result['scoring']['components']['human_candle']['scores']];

        if ($result['reason'] === 'supported' && $result['action'] !== 'hodl') {
            $thresholds = $model['regime_settings'] ?? ['super_confidence' => 0.8, 'super_effective_neighbors' => 6.0];
            $super = $result['confidence'] >= $thresholds['super_confidence']
                && $thresholds['super_effective_neighbors'] <= $result['effective_neighbors'] + 1e-9;
            $context['regime'] = ($super ? 'super_' : '').($result['action'] === 'buy' ? 'bull' : 'bear');
        }

        return [...$result, ...$context, 'k' => $model['k']];
    }

    private function automaticPrediction(array $model, array $payload, array $history, int $decisionAt, array &$context): array
    {
        $vector = FeatureSchema::vector($payload, $model['keys']);
        if ($vector === null) {
            return WeightedKnn::abstain('missing_selected_features');
        }
        $vector = NormalizedVector::from($vector, $model['keys']);
        $context['patterns_evaluated'] = true;
        $context['patterns'] = $this->patterns->predict($model['patterns'], $vector,
            $this->catalog->candidates($history, $model['period']), $decisionAt);
        if ($model['automatic']['status'] !== 'ready') {
            return WeightedKnn::abstain($model['automatic']['reason']);
        }
        if ($model['pattern_keys'] !== []) {
            $vector = [...$vector, ...$this->patterns->features($model['patterns'], $context['patterns'])];
        }
        $weights = [];
        if (($model['lead_lag_keys'] ?? []) !== []) {
            if (($model['lead_lag']['version'] ?? null) !== LeadLagTrainer::VERSION) {
                return WeightedKnn::abstain('model_version_mismatch');
            }
            $leadLag = $this->leadLag->current($model['lead_lag'], $decisionAt);
            $weights = [...array_fill(0, count($vector), 1.0), ...$leadLag['weights']];
            $vector = [...$vector, ...$leadLag['vector']];
            $context['lead_lag'] = $leadLag['signals'];
        }
        $settings = $model['settings'];
        $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);

        return $knn->predict($model['knowledge'], $vector, $model['k'], $decisionAt, $weights);
    }
}
