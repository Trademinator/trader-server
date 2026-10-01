<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\ClientMarketAccess;
use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use App\Models\ClientExecutionReport;
use App\Models\MarketSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ClientExecutionReportController extends Controller
{
    public function index(Request $request, string $subscription, ClientMarketAccess $access): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $reports = ClientExecutionReport::query()->where('user_id', $request->user()->user_id)
            ->where('market_subscription_id', $item->getKey())->orderByDesc('occurred_at_ms')->limit(100)->get();

        return response()->json(['api_version' => 1, 'reports' => $reports->map(fn ($report) => $this->payload($report))]);
    }

    public function store(Request $request, string $subscription, ClientMarketAccess $access, ActionLog $log): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:96'],
            'signal_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(['pending', 'acted', 'skipped', 'rejected', 'failed', 'fill'])],
            'side' => ['nullable', Rule::in(['buy', 'sell'])],
            'reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_.:-]+$/'],
            'protective' => ['sometimes', 'boolean'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['nullable', 'numeric', 'gt:0'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'fee_currency' => ['nullable', 'string', 'max:16', 'regex:/^[A-Z0-9._-]+$/'],
            'exchange_order_id' => ['nullable', 'string', 'max:128'],
            'exchange_trade_id' => ['nullable', 'string', 'max:128'],
            'occurred_at_ms' => ['required', 'integer', 'min:1'],
        ]);
        $data['protective'] = (bool) ($data['protective'] ?? false);

        $signal = MarketSignal::query()->where('market_id', $item->market_id)->find($data['signal_id']);
        if ($signal === null) {
            return response()->json(['error' => ['code' => 'signal_not_found', 'message' => 'The signal is not part of this market.']], 422);
        }
        if (! $item->active && ! ($data['protective'] && in_array($data['event'], ['fill', 'failed', 'rejected'], true))) {
            return response()->json(['error' => ['code' => 'subscription_inactive',
                'message' => 'Inactive subscriptions cannot report new trade decisions. Protective exit/failure reports remain allowed.']], 409);
        }
        if (in_array($data['event'], ['acted', 'fill'], true) && $data['side'] === null) {
            return response()->json(['error' => ['code' => 'side_required', 'message' => 'side is required for acted and fill reports.']], 422);
        }
        if (in_array($data['event'], ['skipped', 'rejected', 'failed'], true) && empty($data['reason'])) {
            return response()->json(['error' => ['code' => 'reason_required', 'message' => 'A machine-readable reason is required for this report.']], 422);
        }
        if ($data['event'] === 'fill' && (! isset($data['quantity'], $data['price']))) {
            return response()->json(['error' => ['code' => 'fill_details_required', 'message' => 'quantity and price are required for fill reports.']], 422);
        }
        if (! $data['protective'] && in_array($data['event'], ['acted', 'fill'], true)
            && ($signal->reason !== 'supported' || ! in_array($signal->action, ['buy', 'sell'], true) || $data['side'] !== $signal->action)) {
            return response()->json(['error' => ['code' => 'signal_action_mismatch',
                'message' => 'Non-protective execution must match a supported directional Server signal.']], 422);
        }

        $canonical = $data;
        ksort($canonical);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        $report = DB::transaction(function () use ($request, $item, $signal, $data, $hash): ClientExecutionReport {
            $existing = ClientExecutionReport::query()->where('user_id', $request->user()->user_id)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409,
                    'This idempotency key was already used with different report data.');

                return $existing;
            }

            return ClientExecutionReport::query()->create([
                'user_id' => $request->user()->user_id,
                'market_subscription_id' => $item->getKey(),
                'market_signal_id' => $signal->getKey(),
                'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $hash,
                'event' => $data['event'], 'side' => $data['side'] ?? null, 'reason' => $data['reason'] ?? null,
                'protective' => $data['protective'], 'quantity' => $data['quantity'] ?? null, 'price' => $data['price'] ?? null,
                'fee' => $data['fee'] ?? null, 'fee_currency' => $data['fee_currency'] ?? null,
                'exchange_order_id' => $data['exchange_order_id'] ?? null, 'exchange_trade_id' => $data['exchange_trade_id'] ?? null,
                'occurred_at_ms' => $data['occurred_at_ms'], 'recorded_at_ms' => now()->getTimestampMs(),
            ]);
        }, 3);

        $log->write('client.execution_reported', ['subscription_id' => $item->getKey(), 'market_id' => $item->market_id,
            'action' => $data['event'], 'outcome' => in_array($data['event'], ['failed', 'rejected'], true) ? 'failed' : 'completed',
            'reason' => $data['reason'] ?? null]);

        return response()->json(['api_version' => 1, 'report' => $this->payload($report)], 201);
    }

    private function payload(ClientExecutionReport $report): array
    {
        return [
            'id' => $report->getKey(), 'signal_id' => $report->market_signal_id,
            'event' => $report->event, 'side' => $report->side, 'reason' => $report->reason,
            'protective' => (bool) $report->protective, 'quantity' => $report->quantity,
            'price' => $report->price, 'fee' => $report->fee, 'fee_currency' => $report->fee_currency,
            'exchange_order_id' => $report->exchange_order_id, 'exchange_trade_id' => $report->exchange_trade_id,
            'occurred_at_ms' => $report->occurred_at_ms, 'recorded_at_ms' => $report->recorded_at_ms,
        ];
    }
}
