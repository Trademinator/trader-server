<?php

namespace App\Http\Controllers;

use App\Domain\Client\ClientDashboardActivity;
use App\Domain\Intelligence\DashboardData;
use App\Domain\MarketData\MarketChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DashboardController extends Controller
{
    public function index(Request $request, DashboardData $dashboard, ClientDashboardActivity $clientActivity): Response|JsonResponse
    {
        $input = $request->validate(['subscription' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'], 'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'favorites' => ['nullable', 'boolean']]);
        $user = $request->user();
        $search = trim($input['q'] ?? '');
        $scope = $user->isOwner() ? ($input['scope'] ?? 'all') : 'mine';
        $favoritesOnly = $request->boolean('favorites');
        if ($request->expectsJson()) {
            $data = $dashboard->markets($user, $input['subscription'] ?? null, $search, $scope, $favoritesOnly);

            return response()->json(['html' => view('dashboard-markets', $data)->render(),
                'count' => $data['subscriptions']->total(),
                ...($user->can('manage-server') ? ['attention_count' => $data['cards']->where('attention', true)->count()] : [])])
                ->header('Cache-Control', 'private, no-store');
        }
        $now = now()->getTimestampMs();
        if ((int) $request->session()->get('dashboard_visit_started_ms', 0) < $now - 1800000) {
            $request->session()->put('dashboard_since_ms', (int) ($user->dashboard_seen_at_ms ?? $now - 86400000));
            $request->session()->put('dashboard_visit_started_ms', $now);
        }
        $data = $dashboard->overview($user, $input['subscription'] ?? null,
            (int) $request->session()->get('dashboard_since_ms'), $search, $scope, $favoritesOnly);
        if ($data['details'] !== null) {
            $subscription = $data['details']['subscription'];
            $chart = $data['details']['chart'];
            $data['details']['chart']['client_events'] = $this->clientEvents($clientActivity, $user, $subscription, $chart);
            $data['details']['client_reports'] = $clientActivity->latestBySignal($user, $subscription, $data['details']['history']);
        }
        $user->forceFill(['dashboard_seen_at_ms' => $now])->save();

        return response()->view('dashboard', $data)->header('Cache-Control', 'private, no-store');
    }

    public function chart(Request $request, string $subscription, DashboardData $dashboard, ClientDashboardActivity $clientActivity): JsonResponse
    {
        $item = $dashboard->accessibleSubscriptions($request->user())->findOrFail($subscription);
        $chart = $dashboard->chart($request->user(), $item->market);
        $chart['client_events'] = $this->clientEvents($clientActivity, $request->user(), $item, $chart);

        return response()->json(['subscription_id' => $item->getKey(), 'symbol' => $item->market->symbol,
            'chart' => $chart])->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, string $subscription, DashboardData $dashboard, MarketChart $chart,
        ClientDashboardActivity $clientActivity): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['older', 'newer', 'earliest'])],
            'anchor_ms' => ['nullable', 'integer', 'min:1'],
            'until_ms' => ['required', 'integer', 'min:1'],
        ]);
        if ($data['until_ms'] > now()->getTimestampMs()) {
            throw ValidationException::withMessages(['until_ms' => 'The chart history ceiling cannot be in the future.']);
        }
        if ($data['direction'] !== 'earliest' && ! isset($data['anchor_ms'])) {
            throw ValidationException::withMessages(['anchor_ms' => 'Continue from the edge of the currently loaded chart.']);
        }
        if (isset($data['anchor_ms']) && $data['anchor_ms'] >= $data['until_ms']) {
            throw ValidationException::withMessages(['anchor_ms' => 'The chart history cursor must stay before the browsing ceiling.']);
        }

        $item = $dashboard->accessibleSubscriptions($request->user())->findOrFail($subscription);
        $page = $chart->page($item->market, $data['direction'], $data['anchor_ms'] ?? null, $data['until_ms'],
            (int) config('dashboard.chart_page_size', 90));

        if ($page['series'] !== []) {
            $from = $page['series'][0]['time'] * 1000;
            $to = $page['series'][array_key_last($page['series'])]['time'] * 1000;
            $page['human_labels'] = $dashboard->humanLabels($request->user(), $item->market, $from, $to);
            $page['client_events'] = $clientActivity->chart($request->user(), $item, $from, $to + 1);
        } else {
            $page['human_labels'] = [];
            $page['client_events'] = [];
        }

        return response()->json(['subscription_id' => $item->getKey(), 'symbol' => $item->market->symbol, 'chart' => $page])
            ->header('Cache-Control', 'private, no-store');
    }

    public function favorite(Request $request, string $subscription, DashboardData $dashboard): RedirectResponse
    {
        $item = $dashboard->accessibleSubscriptions($request->user())->findOrFail($subscription);
        $request->user()->favoriteMarketSubscriptions()->syncWithoutDetaching([$item->getKey()]);

        return $this->dashboardRedirect($request, $item->market->symbol.' added to favourites.');
    }

    public function unfavorite(Request $request, string $subscription, DashboardData $dashboard): RedirectResponse
    {
        $item = $dashboard->accessibleSubscriptions($request->user())->findOrFail($subscription);
        $request->user()->favoriteMarketSubscriptions()->detach($item->getKey());

        return $this->dashboardRedirect($request, $item->market->symbol.' removed from favourites.');
    }

    private function dashboardRedirect(Request $request, string $status): RedirectResponse
    {
        $input = $request->validate([
            'selected' => ['nullable', 'uuid'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'favorites' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($input['q'] ?? '');
        $page = (int) ($input['page'] ?? 1);
        $query = array_filter([
            'subscription' => $input['selected'] ?? null,
            'scope' => $request->user()->isOwner() ? ($input['scope'] ?? 'all') : null,
            'favorites' => $request->boolean('favorites') ? 1 : null,
            'q' => $search !== '' ? $search : null,
            'page' => $page > 1 ? $page : null,
        ], fn (mixed $value): bool => $value !== null);

        return redirect()->route('dashboard', $query)->with('status', $status);
    }

    private function clientEvents(ClientDashboardActivity $activity, $user, $subscription, array $chart): array
    {
        if ($chart['series'] === []) {
            return [];
        }
        $from = (int) $chart['series'][0]['time'] * 1000;
        $to = (int) $chart['series'][array_key_last($chart['series'])]['time'] * 1000 + 1;

        return $activity->chart($user, $subscription, $from, $to);
    }
}
