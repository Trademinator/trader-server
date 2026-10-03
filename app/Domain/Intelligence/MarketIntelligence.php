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
        private HumanGuidance $humanGuidance,
        private CandleGuidance $candleGuidance,
        private TickerRepository $tickers,
        private SignalFreshness $freshness,
    ) {}

    public function build(string $exchange, string $symbol, string $period, ?string $dataset = null,
        string $schema = 'core', ?int $fromMs = null, ?int $toMs = null, ?int $asOfMs = null,
        ?string $generation = null, array $buildPerformance = []): array
    {
        $buildPerformance['started_at'] ??= now()->toIso8601String();
        $buildPerformance['started_monotonic_ns'] = hrtime(true);
        $buildPerformance['stages'] ??= [];
        $deadline = microtime(true) + config('intelligence.max_seconds');
        $lock = Cache::lock('trademinator:intelligence-build:'.ModelStore::marketKey($exchange, $symbol, $period), 720);
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
                $fromMs ??= (clone $query)->where('available_at_ms', '<=', $toMs)->orderByDesc('microtimestamp')
                    ->limit(config('intelligence.max_rows'))->pluck('available_at_ms')->last() ?? 0;
                $definition = new SemanticLabels(config('intelligence.horizon'), config('intelligence.lookback'),
                    config('intelligence.minimum_move_bps'), config('intelligence.extreme_fraction'));
                $manifest = $this->datasets->build($exchange, $symbol, $period, $definition, $schema,
                    fromMs: (int) $fromMs, toMs: $toMs, asOfMs: $asOfMs);
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
        if ($model['trained_as_of_ms'] < $asOfMs - config('intelligence.max_model_age_days') * 86400000) {
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
        $vector = FeatureSchema::vector($current->payload, $model['keys']);
        if ($vector === null) {
            return [...WeightedKnn::abstain('missing_selected_features'), ...$context];
        }
        $vector = NormalizedVector::from($vector, $model['keys']);
        $humanVector = $vector;
        $context['patterns_evaluated'] = true;
        $context['patterns'] = $this->patterns->predict($model['patterns'], $vector,
            $this->catalog->candidates($history, $period), $current->available_at_ms);
        if ($model['status'] !== 'ready') {
            return [...WeightedKnn::abstain($model['reason']), ...$context];
        }
        if ($model['pattern_keys'] !== []) {
            $vector = [...$vector, ...$this->patterns->features($model['patterns'], $context['patterns'])];
        }
        $weights = [];
        if (($model['lead_lag_keys'] ?? []) !== []) {
            if (($model['lead_lag']['version'] ?? null) !== LeadLagTrainer::VERSION) {
                return [...WeightedKnn::abstain('model_version_mismatch'), ...$context];
            }
            $leadLag = $this->leadLag->current($model['lead_lag'], $current->available_at_ms);
            $weights = [...array_fill(0, count($vector), 1.0), ...$leadLag['weights']];
            $vector = [...$vector, ...$leadLag['vector']];
            $context['lead_lag'] = $leadLag['signals'];
        }
        $settings = $model['settings'];
        if (($model['human_keys'] ?? []) !== []) {
            $human = $model['human_guidance'];
            if (! config('human_training.enabled') || ($human['version'] ?? null) !== HumanGuidance::VERSION
                || ! ($human['influence'] ?? false) || ($human['reviews_submitted_by_ms'] ?? PHP_INT_MAX) >= $current->available_at_ms) {
                return [...WeightedKnn::abstain('human_guidance_unavailable'), ...$context];
            }
            $humanFeatures = $this->humanGuidance->features($human, $humanVector);
            $weights = [...($weights ?: array_fill(0, count($vector), 1.0)), ...array_fill(0, count($humanFeatures), 1.0)];
            $vector = [...$vector, ...$humanFeatures];
            $context['human_guidance'] = ['status' => 'validated', 'opinion_shares' => array_combine(HumanTraining::LABELS, $humanFeatures)];
        }
        if (($model['candle_keys'] ?? []) !== []) {
            $candle = $model['candle_guidance'];
            if (! config('human_training.enabled') || ($candle['version'] ?? null) !== CandleGuidance::VERSION
                || ! ($candle['influence'] ?? false) || ($candle['labels_updated_by_ms'] ?? PHP_INT_MAX) >= $current->available_at_ms) {
                return [...WeightedKnn::abstain('candle_guidance_unavailable'), ...$context];
            }
            $candleFeatures = $this->candleGuidance->features($candle, $humanVector);
            $weights = [...($weights ?: array_fill(0, count($vector), 1.0)), ...array_fill(0, count($candleFeatures), 1.0)];
            $vector = [...$vector, ...$candleFeatures];
            $context['candle_guidance'] = ['status' => 'validated',
                'action_shares' => array_combine(CandleTraining::ACTIONS, $candleFeatures)];
        }
        $knn = new WeightedKnn($settings['max_distance'], $settings['min_effective_neighbors'], $settings['min_confidence']);
        $result = $knn->predict($model['knowledge'], $vector, $model['k'], $current->available_at_ms, $weights);

        if ($result['reason'] === 'supported' && $result['action'] !== 'hodl') {
            $thresholds = $model['regime_settings'] ?? ['super_confidence' => 0.8, 'super_effective_neighbors' => 6.0];
            $super = $result['confidence'] >= $thresholds['super_confidence']
                && $thresholds['super_effective_neighbors'] <= $result['effective_neighbors'] + 1e-9;
            $context['regime'] = ($super ? 'super_' : '').($result['action'] === 'buy' ? 'bull' : 'bear');
        }

        return [...$result, ...$context, 'k' => $model['k']];
    }
}
