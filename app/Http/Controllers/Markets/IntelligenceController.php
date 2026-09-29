<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\WeightedKnn;
use App\Http\Controllers\Controller;
use App\Models\MarketSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class IntelligenceController extends Controller
{
    public function show(Request $request, string $subscription, MarketIntelligence $intelligence, ModelStore $models, IntelligenceReadiness $readiness): View
    {
        $item = MarketSubscription::query()->with('market.exchange', 'market.feed')
            ->where('user_id', $request->user()->user_id)->where('active', true)->findOrFail($subscription);
        $period = $item->market->feed?->selected_period;
        $report = null;
        try {
            $signal = $period === null ? [...WeightedKnn::abstain('period_pending'), 'patterns' => []]
                : $intelligence->predict($item->market->exchange->class, $item->market->symbol, $period);
            if (($signal['model_id'] ?? null) !== null) {
                $report = $models->report($signal['model_id']);
            }
        } catch (Throwable $error) {
            report($error);
            $signal = [...WeightedKnn::abstain('model_unavailable'), 'patterns' => []];
        }
        $explanation = match ($signal['reason']) {
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
            'no_similar_history' => 'No historical examples are close enough to the current market state.',
            'insufficient_effective_neighbors' => 'Too few effectively weighted examples support a decision.',
            'tied_votes' => 'The strongest historical votes are tied.',
            'weak_consensus' => 'The historical examples do not agree strongly enough.',
            default => 'The model is currently unavailable. No directional signal is being issued.',
        };

        $progress = $readiness->describe($item->market->exchange->class, $item->market->symbol, $period,
            $item->market->feed, $report, $signal);

        return view('markets.intelligence', compact('item', 'period', 'signal', 'report', 'explanation', 'progress'));
    }
}
