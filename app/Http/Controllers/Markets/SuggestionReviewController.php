<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketSuggestions\CandleEvidence;
use App\Domain\MarketSuggestions\PairSuggestions;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SuggestionReviewController extends Controller
{
    public function show(Request $request, MarketCatalog $catalog, PairSuggestions $suggestions, CandleEvidence $evidence): Response|JsonResponse|RedirectResponse
    {
        $input = $request->validate([
            'exchange' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/D'],
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+\/[A-Z0-9._-]+$/D'],
            'discovery' => ['sometimes', 'boolean'],
        ]);
        $subscription = MarketSubscription::query()->with('market.exchange', 'market.feed')
            ->where('user_id', $request->user()->user_id)
            ->whereHas('market', fn (Builder $query) => $query->where('symbol', $input['symbol'])
                ->whereHas('exchange', fn (Builder $query) => $query->where('class', $input['exchange'])))
            ->first();
        $market = $subscription?->market;
        if ($market === null) {
            $markets = Market::query()->with('exchange', 'feed')
                ->where('symbol', $input['symbol'])
                ->whereHas('exchange', fn (Builder $query) => $query->where('class', $input['exchange']))
                ->limit(2)->get();
            if ($markets->count() === 1) {
                $market = $markets->first();
            }
        }
        $profile = MarketPreferenceProfile::query()->find($request->user()->user_id);
        if (! $profile) {
            if ($market !== null) {
                return $this->technicalReview($request, $market, $subscription, $evidence, $input,
                    'No saved preferences are available for a personal match assessment.');
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Your saved preferences are no longer available. Return to pair suggestions.'], 409)->header('Cache-Control', 'private, no-store')
                : redirect()->route('markets.suggestions')->with('status', 'Save your preferences to review a suggested pair.');
        }
        $answers = array_replace(Questionnaire::defaults(), $profile->answers);
        if ($input['exchange'] !== $answers['exchange']) {
            abort_if($market === null, 404);

            return $this->technicalReview($request, $market, $subscription, $evidence, $input,
                'Your saved preferences describe a different exchange. Holdings and access confirmation from that exchange are not applied here.');
        }
        $exchange = null;
        $results = null;
        $item = null;
        $failure = null;
        $status = 200;
        try {
            $matches = Exchange::query()->where('class', $answers['exchange'])->limit(2)->get();
            if ($matches->count() !== 1 || ! in_array($answers['exchange'], array_column($catalog->exchanges(), 'value'), true)) {
                $failure = 'Your saved exchange is no longer available. Return to suggestions and choose another exchange.';
            } else {
                $exchange = $matches->first();
                $results = $suggestions->suggest($request->user(), $answers, $exchange, $input['symbol'], $request->boolean('discovery'));
                $item = collect($results['items'])->firstWhere('symbol', $input['symbol']);
                if ($item === null) {
                    $failure = 'This pair is no longer in your current shortlist. Your answers, market availability or stored evidence may have changed. Return to suggestions to review the latest matches.';
                }
            }
            if ($failure !== null) {
                $status = 409;
            }
        } catch (Throwable $exception) {
            $error = MarketCatalogException::reportFailure($exception, 'pair-review');
            $failure = $error['message'].' Reference: '.$error['reference'];
            $status = 503;
        }
        if ($failure !== null && $status === 409 && $market !== null) {
            return $this->technicalReview($request, $market, $subscription, $evidence, $input,
                'This pair is not in your current suggestions. Availability, preferences or screening results may have changed.');
        }
        if ($request->expectsJson()) {
            return response()->json($failure !== null ? ['message' => $failure] : [
                'symbol' => $item['symbol'], 'exchange' => $exchange->class,
                'review_type' => 'preference',
                'evidence' => $item['evidence'], 'checked_at' => now()->utc()->toIso8601String(),
            ], $status)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('markets.review', [
            'answers' => $answers, 'choices' => Questionnaire::choices(), 'bands' => Questionnaire::BANDS,
            'exchange' => $exchange, 'results' => $results, 'item' => $item, 'failure' => $failure,
            'reviewUrl' => route('markets.suggestions.review', $input),
        ], $status)->header('Cache-Control', 'private, no-store');
    }

    /** @param array{exchange: string, symbol: string} $input */
    private function technicalReview(Request $request, Market $market, ?MarketSubscription $subscription, CandleEvidence $inspector, array $input, string $notice): Response|JsonResponse
    {
        $market->loadMissing('exchange', 'feed');
        $evidence = $inspector->inspect($input['exchange'], $input['symbol'], $market->feed?->selected_period,
            'unsure', includeSeries: true);
        if ($request->expectsJson()) {
            return response()->json([
                'symbol' => $input['symbol'], 'exchange' => $input['exchange'],
                'review_type' => 'technical',
                'evidence' => $evidence, 'checked_at' => now()->utc()->toIso8601String(),
            ])->header('Cache-Control', 'private, no-store');
        }

        [$base, $quote] = explode('/', $input['symbol']);

        return response()->view('markets.subscription-review', [
            'subscription' => $subscription, 'exchange' => $market->exchange, 'feed' => $market->feed, 'notice' => $notice,
            'evidence' => $evidence, 'reviewUrl' => route('markets.suggestions.review', $input),
            'item' => ['symbol' => $input['symbol'], 'base' => $base, 'quote' => $quote, 'subscribed' => $subscription?->active ?? false],
            'market' => ['tick_size' => $market->tick_size],
        ])->header('Cache-Control', 'private, no-store');
    }
}
