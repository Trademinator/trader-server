<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketSuggestions\PairSuggestions;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketPreferenceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SuggestionReviewController extends Controller
{
    public function show(Request $request, MarketCatalog $catalog, PairSuggestions $suggestions): Response|JsonResponse|RedirectResponse
    {
        $input = $request->validate([
            'exchange' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/D'],
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+\/[A-Z0-9._-]+$/D'],
        ]);
        $profile = MarketPreferenceProfile::query()->find($request->user()->user_id);
        if (! $profile) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Your saved preferences are no longer available. Return to pair suggestions.'], 409)->header('Cache-Control', 'private, no-store')
                : redirect()->route('markets.suggestions')->with('status', 'Save your preferences to review a suggested pair.');
        }
        $answers = array_replace(Questionnaire::defaults(), $profile->answers);
        abort_unless($input['exchange'] === $answers['exchange'], 404);
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
                $results = $suggestions->suggest($request->user(), $answers, $exchange, $input['symbol']);
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
        if ($request->expectsJson()) {
            return response()->json($failure !== null ? ['message' => $failure] : [
                'symbol' => $item['symbol'], 'exchange' => $exchange->class,
                'evidence' => $item['evidence'], 'checked_at' => now()->utc()->toIso8601String(),
            ], $status)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('markets.review', [
            'answers' => $answers, 'choices' => Questionnaire::choices(), 'bands' => Questionnaire::BANDS,
            'exchange' => $exchange, 'results' => $results, 'item' => $item, 'failure' => $failure,
            'reviewUrl' => route('markets.suggestions.review', $input),
        ], $status)->header('Cache-Control', 'private, no-store');
    }
}
