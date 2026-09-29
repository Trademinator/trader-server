<?php

namespace App\Http\Controllers;

use App\Domain\Intelligence\DashboardData;
use App\Domain\MarketData\MarketChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DashboardController extends Controller
{
    public function index(Request $request, DashboardData $dashboard): Response
    {
        $input = $request->validate(['subscription' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $user = $request->user();
        $now = now()->getTimestampMs();
        if ((int) $request->session()->get('dashboard_visit_started_ms', 0) < $now - 1800000) {
            $request->session()->put('dashboard_since_ms', (int) ($user->dashboard_seen_at_ms ?? $now - 86400000));
            $request->session()->put('dashboard_visit_started_ms', $now);
        }
        $data = $dashboard->overview($user, $input['subscription'] ?? null, (int) $request->session()->get('dashboard_since_ms'));
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
