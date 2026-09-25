<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketSubscriptions;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

final class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        return view('markets.index', [
            'subscriptions' => MarketSubscription::query()->with('market.exchange', 'market.feed')
                ->where('user_id', $request->user()->user_id)->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request, MarketSubscriptions $subscriptions): RedirectResponse
    {
        $input = $request->validate([
            'exchange' => ['required', 'string', 'max:32'],
            'symbol' => ['required', 'string', 'max:32'],
            'tick_size' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/D'],
        ]);
        $matches = Exchange::query()->where('class', $input['exchange'])->limit(2)->get();
        if ($matches->count() !== 1) {
            return back()->withInput()->withErrors(['exchange' => 'Choose one configured exchange ID.']);
        }
        try {
            $subscriptions->subscribe($request->user(), $matches->first(), $input['symbol'], $input['tick_size']);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['symbol' => $exception->getMessage()]);
        }

        return redirect()->route('markets.index')->with('status', 'Market subscription active.');
    }

    public function destroy(Request $request, string $subscription, MarketSubscriptions $subscriptions): RedirectResponse
    {
        $item = MarketSubscription::query()->with('market')
            ->where('user_id', $request->user()->user_id)->findOrFail($subscription);
        $subscriptions->unsubscribe($request->user(), $item->market);

        return redirect()->route('markets.index')->with('status', 'Market subscription inactive.');
    }
}
