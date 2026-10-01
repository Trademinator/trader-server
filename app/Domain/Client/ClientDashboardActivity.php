<?php

namespace App\Domain\Client;

use App\Models\ClientExecutionReport;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Collection;

final class ClientDashboardActivity
{
    public function chart(User $user, MarketSubscription $subscription, int $fromMs, int $toMs): array
    {
        if ($toMs < $fromMs) {
            return [];
        }

        return ClientExecutionReport::query()->where('user_id', $user->user_id)
            ->where('market_subscription_id', $subscription->getKey())
            ->whereBetween('occurred_at_ms', [$fromMs, $toMs])
            ->orderBy('occurred_at_ms')->orderBy('client_execution_report_id')->limit(300)->get()
            ->map(fn (ClientExecutionReport $report): array => [
                'id' => $report->getKey(), 'signal_id' => $report->market_signal_id,
                'event' => $report->event, 'side' => $report->side, 'reason' => $report->reason,
                'price' => $report->price === null ? null : (float) $report->price,
                'quantity' => $report->quantity === null ? null : (float) $report->quantity,
                'protective' => (bool) $report->protective, 'occurred_at_ms' => $report->occurred_at_ms,
            ])->all();
    }

    public function latestBySignal(User $user, MarketSubscription $subscription, Collection $signals): Collection
    {
        $ids = $signals->pluck('market_signal_id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return ClientExecutionReport::query()->where('user_id', $user->user_id)
            ->where('market_subscription_id', $subscription->getKey())->whereIn('market_signal_id', $ids)
            ->orderBy('occurred_at_ms')->orderBy('client_execution_report_id')->get()->groupBy('market_signal_id');
    }
}
