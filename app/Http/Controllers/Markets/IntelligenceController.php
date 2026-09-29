<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalJournal;
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
        $explanation = SignalJournal::explain($signal['reason']);

        $progress = $readiness->describe($item->market->exchange->class, $item->market->symbol, $period,
            $item->market->feed, $report, $signal);

        return view('markets.intelligence', compact('item', 'period', 'signal', 'report', 'explanation', 'progress'));
    }
}
