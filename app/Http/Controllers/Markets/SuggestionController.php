<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketSuggestions\PairSuggestions;
use App\Domain\MarketSuggestions\Questionnaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarketPreferencesRequest;
use App\Models\Exchange;
use App\Models\MarketPreferenceProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SuggestionController extends Controller
{
    public function index(Request $request, MarketCatalog $catalog, PairSuggestions $suggestions): Response
    {
        $profile = MarketPreferenceProfile::query()->find($request->user()->user_id);
        $answers = array_replace(Questionnaire::defaults(), $profile?->answers ?? []);
        $exchanges = [];
        $results = null;
        $failure = null;
        try {
            $exchanges = $catalog->exchanges();
            if ($profile && $request->boolean('show')) {
                $matches = Exchange::query()->where('class', $answers['exchange'])->limit(2)->get();
                if ($matches->count() !== 1 || ! in_array($answers['exchange'], array_column($exchanges, 'value'), true)) {
                    $failure = 'Your saved exchange is no longer available for suggestions. Choose another exchange below.';
                } else {
                    $results = $suggestions->suggest($request->user(), $answers, $matches->first());
                }
            }
        } catch (Throwable $exception) {
            $error = MarketCatalogException::reportFailure($exception, 'pair-suggestions');
            $failure = $error['message'].' Reference: '.$error['reference'];
        }

        return response()->view('markets.suggestions', [
            'answers' => $answers, 'hasProfile' => $profile !== null, 'exchanges' => $exchanges,
            'choices' => Questionnaire::choices(), 'bands' => Questionnaire::BANDS,
            'countries' => Questionnaire::countries(), 'provinces' => Questionnaire::PROVINCES,
            'results' => $results, 'failure' => $failure,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(MarketPreferencesRequest $request): RedirectResponse
    {
        MarketPreferenceProfile::query()->updateOrCreate(['user_id' => $request->user()->user_id], ['answers' => $request->answers()]);

        return redirect()->route('markets.suggestions', ['show' => 1])->with('status', 'Preferences saved. Your subscriptions have not changed.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        MarketPreferenceProfile::query()->where('user_id', $request->user()->user_id)->delete();
        $request->session()->forget('_old_input');

        return redirect()->route('markets.suggestions')->with('status', 'Your saved answers were deleted. Your subscriptions are unchanged.');
    }
}
