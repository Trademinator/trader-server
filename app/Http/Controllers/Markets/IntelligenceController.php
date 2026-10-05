<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CoinGeckoReadiness;
use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalJournal;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\MarketCatalog;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class IntelligenceController extends Controller
{
    public function show(Request $request, string $subscription, MarketIntelligence $intelligence, ModelStore $models, IntelligenceReadiness $readiness, MarketCatalog $catalog, CoinGeckoReadiness $contextReadiness): View
    {
        $item = MarketSubscription::query()->with('market.exchange', 'market.feed')
            ->where('user_id', $request->user()->user_id)->where('active', true)->findOrFail($subscription);
        $period = $item->market->feed?->selected_period;
        $coingecko = $contextReadiness->forMarkets([$item->market])->get(ModelStore::marketKey(
            $item->market->exchange->class, $item->market->symbol, $period ?? ''));
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

        $exchangeChoices = collect();
        try {
            $exchangeChoices = collect($catalog->exchanges())->keyBy('value');
        } catch (Throwable $error) {
            report($error);
        }
        $peerMarkets = Market::query()
            ->with(['exchange', 'feed', 'subscriptions' => fn ($query) => $query
                ->where('user_id', $request->user()->user_id)->where('active', true)])
            ->where('symbol', $item->market->symbol)
            ->where('market_id', '!=', $item->market_id)
            ->get()
            ->filter(fn (Market $market): bool => $exchangeChoices->isEmpty() || $exchangeChoices->has($market->exchange->class))
            ->map(function (Market $market) use ($exchangeChoices): array {
                $choice = $exchangeChoices->get($market->exchange->class, []);

                return [
                    'exchange' => $market->exchange,
                    'label' => $choice['label'] ?? $market->exchange->name,
                    'logo_url' => $choice['logo_url'] ?? null,
                    'period' => $market->feed?->selected_period,
                    'following' => $market->subscriptions->isNotEmpty(),
                ];
            })
            ->sortBy(fn (array $peer): string => mb_strtolower($peer['label']))
            ->values();

        return view('markets.intelligence', compact('item', 'period', 'signal', 'report', 'explanation', 'progress', 'peerMarkets', 'coingecko'));
    }
}
