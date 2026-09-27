<?php

namespace App\Http\Controllers\Markets;

use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketSubscriptions;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

final class SubscriptionController extends Controller
{
    public function index(Request $request, MarketCatalog $catalog): View
    {
        $catalogueError = null;
        try {
            $exchanges = $catalog->exchanges();
        } catch (Throwable $exception) {
            $failure = MarketCatalogException::reportFailure($exception, 'exchange-list');
            $catalogueError = $failure['message'].' Reference: '.$failure['reference'];
            $exchanges = [];
        }
        $exchangeChoices = collect($exchanges)->keyBy('value');
        $subscriptionGroups = MarketSubscription::query()->with('market.exchange', 'market.feed')
            ->where('user_id', $request->user()->user_id)->get()
            ->groupBy(fn (MarketSubscription $item): string => $item->market->exchange_id)
            ->map(function ($items) use ($exchangeChoices): array {
                $exchange = $items->first()->market->exchange;
                $choice = $exchangeChoices->get($exchange->class);

                return [
                    'exchange_id' => $exchange->exchange_id,
                    'name' => $choice['label'] ?? $exchange->name,
                    'logo_url' => $choice['logo_url'] ?? null,
                    'subscriptions' => $items->sort(fn (MarketSubscription $a, MarketSubscription $b): int => strcasecmp($a->market->symbol, $b->market->symbol)
                        ?: strcasecmp($a->market->feed->selected_period ?? '', $b->market->feed->selected_period ?? '')
                        ?: strcmp($a->market_subscription_id, $b->market_subscription_id))->values(),
                ];
            })
            ->sort(fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: strcmp($a['exchange_id'], $b['exchange_id']))->values();

        return view('markets.index', [
            'exchanges' => $exchanges,
            'catalogueError' => $catalogueError,
            'subscriptionGroups' => $subscriptionGroups,
            'prefillExchange' => is_string($request->query('exchange')) ? $request->query('exchange') : '',
            'prefillSymbol' => is_string($request->query('symbol')) ? $request->query('symbol') : '',
        ]);
    }

    public function options(string $exchange, MarketCatalog $catalog): JsonResponse
    {
        $matches = Exchange::query()->where('class', $exchange)->limit(2)->get();
        if ($matches->count() !== 1) {
            abort(404);
        }
        try {
            return response()->json($catalog->forExchange($matches->first()));
        } catch (Throwable $exception) {
            return response()->json(MarketCatalogException::reportFailure($exception, $exchange),
                MarketCatalogException::fromFailure($exception)->status);
        }
    }

    public function store(Request $request, MarketSubscriptions $subscriptions, MarketCatalog $catalog): RedirectResponse
    {
        $input = $request->validate([
            'exchange' => ['required', 'string', 'max:32'],
            'symbol' => ['required', 'string', 'max:32'],
        ]);
        $matches = Exchange::query()->where('class', $input['exchange'])->limit(2)->get();
        if ($matches->count() !== 1) {
            return back()->withInput()->withErrors(['exchange' => 'Choose one configured exchange ID.']);
        }
        try {
            $tickSize = $catalog->tickSizeFor($matches->first(), $input['symbol']);
            if ($tickSize === null) {
                throw new InvalidArgumentException('This pair has no fixed price tick size available from CCXT.');
            }
            $subscriptions->subscribe($request->user(), $matches->first(), $input['symbol'], $tickSize, spotOnly: true);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['symbol' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $failure = MarketCatalogException::reportFailure($exception, $input['exchange']);

            return back()->withInput()->withErrors(['exchange' => $failure['message'].' Reference: '.$failure['reference']]);
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
