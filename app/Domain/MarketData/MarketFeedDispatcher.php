<?php

namespace App\Domain\MarketData;

use App\Jobs\CollectMarketFeed;
use App\Models\MarketFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MarketFeedDispatcher
{
    public function dispatchDue(int $limit = 100): int
    {
        $dispatched = 0;
        $ids = MarketFeed::query()->where('next_pull_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->orderBy('next_pull_at')->limit($limit)->pluck('market_id');

        foreach ($ids as $marketId) {
            $token = (string) Str::uuid7();
            // The conditional update is a database claim. The cache scheduler lock
            // reduces contention; this claim remains safe with multiple HTTP nodes.
            $claimed = DB::table('market_feeds')->where('market_id', $marketId)
                ->where('next_pull_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                    ->whereColumn('market_subscriptions.market_id', 'market_feeds.market_id')->where('active', true))
                ->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(15), 'status' => 'queued', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            try {
                CollectMarketFeed::dispatch((string) $marketId, $token);
                $dispatched++;
            } catch (Throwable $exception) {
                DB::table('market_feeds')->where('market_id', $marketId)->where('lease_token', $token)
                    ->update(['lease_token' => null, 'lease_until' => null, 'status' => 'error',
                        'last_error' => mb_substr($exception->getMessage(), 0, 1000), 'updated_at' => now()]);
                throw $exception;
            }
        }

        return $dispatched;
    }
}
