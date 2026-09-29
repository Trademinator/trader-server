<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketSuggestions\PairSuggestions;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketPreferenceProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DashboardSuggestionController extends Controller
{
    public function index(Request $request, PairSuggestions $suggestions, MarketCatalog $catalog): Response
    {
        $profile = MarketPreferenceProfile::query()->find($request->user()->user_id);
        $results = null;
        $exchange = null;
        $failure = null;
        if ($profile !== null) {
            $answers = array_replace(Questionnaire::defaults(), $profile->answers);
            try {
                $matches = Exchange::query()->where('class', $answers['exchange'])->limit(2)->get();
                if ($matches->count() !== 1 || ! in_array($answers['exchange'], array_column($catalog->exchanges(), 'value'), true)) {
                    $failure = 'Your saved exchange is unavailable. Review your preferences.';
                } else {
                    $exchange = $matches->first();
                    $results = $suggestions->suggest($request->user(), $answers, $exchange, discoveryOnly: true);
                }
            } catch (Throwable $error) {
                report($error);
                $failure = 'Suggestions are temporarily unavailable. Your subscriptions are unchanged.';
            }
        }

        return response()->view('markets.dashboard-suggestions', compact('profile', 'results', 'exchange', 'failure'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate(['exchange' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/D'],
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+\/[A-Z0-9._-]+$/D']]);
        DB::table('market_suggestion_dismissals')->upsert([
            'user_id' => $request->user()->user_id, ...$input, 'dismissed_at' => now(),
        ], ['user_id', 'exchange', 'symbol'], ['dismissed_at']);

        return redirect()->route('dashboard')->with('status', 'Suggestion hidden for 30 days. Your subscriptions are unchanged.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::table('market_suggestion_dismissals')->where('user_id', $request->user()->user_id)->delete();

        return redirect()->route('dashboard')->with('status', 'Dismissed suggestions restored.');
    }
}
