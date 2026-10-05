<?php

namespace App\Domain\Intelligence;

use App\Models\Market;
use App\Models\MarketSignal;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SignalJournal
{
    public function __construct(private MarketIntelligence $intelligence) {}

    public function record(Market $market): ?MarketSignal
    {
        $market->loadMissing('exchange', 'feed');
        $period = $market->feed?->selected_period;
        if ($period === null || ! $market->subscriptions()->where('active', true)->exists()) {
            return null;
        }
        $lock = Cache::lock('trademinator:signal:'.$market->market_id, 180);
        if (! $lock->get()) {
            return null;
        }
        try {
            try {
                $signal = $this->intelligence->predict($market->exchange->class, $market->symbol, $period);
            } catch (Throwable $error) {
                report($error);
                $signal = [...WeightedKnn::abstain('model_unavailable'), 'patterns' => []];
            }
            $signal['explanation'] = self::explain($signal['reason']);
            $signal['execution_status'] = 'unknown';
            if (! $market->subscriptions()->where('active', true)->exists()) {
                return null;
            }
            $previous = $market->latestSignal()->first();
            if ($previous !== null && $previous->period === $period && $previous->model_id === ($signal['model_id'] ?? null)
                && $previous->decision_at_ms === ($signal['decision_at_ms'] ?? null) && $previous->reason === $signal['reason']
                && $previous->action === $signal['action'] && ($previous->payload['regime'] ?? null) === ($signal['regime'] ?? null)
                && ($previous->payload['reference_price'] ?? null) === ($signal['reference_price'] ?? null)
                && ($previous->payload['reference_price_source'] ?? null) === ($signal['reference_price_source'] ?? null)
                && ($previous->payload['action_meaning'] ?? null) === ($signal['action_meaning'] ?? null)
                && ($previous->payload['scoring'] ?? null) == ($signal['scoring'] ?? null)) {
                return $previous;
            }
            // A recovery after an intervening state is a new observation, even for the same source candle.
            $key = hash('sha256', json_encode([$market->market_id, $period, $signal['model_id'] ?? null,
                $signal['decision_at_ms'] ?? null, $signal['reason'], $previous?->getKey()], JSON_THROW_ON_ERROR));
            $changed = $previous === null || $previous->period !== $period || $previous->model_id !== ($signal['model_id'] ?? null)
                || $previous->action !== $signal['action'] || $previous->reason !== $signal['reason']
                || ($previous->payload['regime'] ?? null) !== ($signal['regime'] ?? null);

            return MarketSignal::query()->firstOrCreate(['snapshot_key' => $key], [
                'market_id' => $market->market_id, 'period' => $period, 'model_id' => $signal['model_id'] ?? null,
                'decision_at_ms' => $signal['decision_at_ms'] ?? null, 'recorded_at_ms' => now()->getTimestampMs(),
                'is_change' => $changed, 'action' => $signal['action'], 'reason' => $signal['reason'], 'payload' => $signal,
            ]);
        } finally {
            $lock->release();
        }
    }

    public static function label(string $action, string $reason): string
    {
        return $reason === 'supported' ? ($action === 'hodl' ? 'HOLD' : strtoupper($action)) : 'Waiting for evidence';
    }

    public static function explain(string $reason): string
    {
        return match ($reason) {
            'supported' => 'Similar historical market states support this signal.',
            'no_model' => 'Training has not completed for this market yet.',
            'period_pending' => 'The collector is still selecting a reliable candle period.',
            'no_eligible_k' => 'Historical evidence has not passed the semantic validation requirements.',
            'holdout_failed' => 'The selected model did not pass the separate later evaluation period.',
            'stale_model' => 'The trained model is too old. A fresh training run is needed.',
            'stale_features' => 'Recent closed-candle features are not available.',
            'no_post_training_candle' => 'Waiting for a closed candle after the training cutoff.',
            'missing_features', 'missing_selected_features' => 'The model needs more complete feature history.',
            'source_feature_mismatch' => 'Stored candles and features do not match. Features need rebuilding.',
            'model_version_mismatch' => 'The model needs rebuilding with the current intelligence version.',
            'no_similar_history' => 'No historical examples are close enough to the current market state.',
            'insufficient_effective_neighbors' => 'Too few effectively weighted examples support a decision.',
            'tied_votes' => 'The strongest historical votes are tied.',
            'tied_model_scores' => 'The combined model scores are tied.',
            'weak_model_consensus' => 'The combined model scores do not agree strongly enough.',
            'no_supported_models', 'no_weighted_model' => 'No enabled model has sufficient evidence for scoring.',
            'weak_consensus' => 'The historical examples do not agree strongly enough.',
            default => 'The model is currently unavailable. No directional signal is being issued.',
        };
    }
}
