<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\ClientMarketAccess;
use App\Domain\Client\ClientPaperTrading;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaperTradingController extends Controller
{
    public function show(Request $request, string $subscription, ClientMarketAccess $access, ClientPaperTrading $paper): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $data = $request->validate(['best_bid' => ['nullable', 'numeric', 'gt:0']]);

        return response()->json(['api_version' => 1, 'paper' => $paper->current($item, $data['best_bid'] ?? null)]);
    }

    public function store(Request $request, string $subscription, ClientMarketAccess $access, ClientPaperTrading $paper): JsonResponse
    {
        $item = $access->active($request->user(), $subscription);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:96'],
            'reported_at_ms' => ['required', 'integer', 'min:1'],
            'best_bid' => ['required', 'numeric', 'gt:0'],
            'best_ask' => ['required', 'numeric', 'gt:0'],
            'taker_fee_bps' => ['required', 'numeric', 'min:0', 'max:10000'],
            'requested_quote' => ['nullable', 'numeric', 'gt:0'],
            'minimum_amount' => ['nullable', 'numeric', 'gt:0'],
            'minimum_cost' => ['nullable', 'numeric', 'gt:0'],
            'amount_step' => ['nullable', 'numeric', 'gt:0'],
        ]);
        if ((float) $data['best_ask'] < (float) $data['best_bid']) {
            return response()->json(['error' => ['code' => 'invalid_order_book', 'message' => 'best_ask must be at least best_bid.']], 422);
        }

        return response()->json($paper->execute($item, $data));
    }
}
