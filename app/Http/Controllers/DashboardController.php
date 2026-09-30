<?php

namespace App\Http\Controllers;

use App\Domain\Intelligence\DashboardData;
use App\Domain\MarketData\MarketChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DashboardController extends Controller
{
    public function index(Request $request, DashboardData $dashboard): Response|JsonResponse
    {
        $input = $request->validate(['subscription' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $search = trim($input['q'] ?? '');
        if ($request->expectsJson()) {
            $data = $dashboard->markets($user, $input['subscription'] ?? null, $search);

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
        $data = $dashboard->overview($user, $input['subscription'] ?? null, (int) $request->session()->get('dashboard_since_ms'), $search);
        $user->forceFill(['dashboard_seen_at_ms' => $now])->save();

        return response()->view('dashboard', $data)->header('Cache-Control', 'private, no-store');
    }

    public function chart(Request $request, string $subscription, DashboardData $dashboard, MarketChart $chart): JsonResponse
    {
        $item = $dashboard->subscriptions($request->user())->findOrFail($subscription);

        return response()->json(['subscription_id' => $item->getKey(), 'symbol' => $item->market->symbol,
            'chart' => $chart->data($item->market)])->header('Cache-Control', 'private, no-store');
    }
}
