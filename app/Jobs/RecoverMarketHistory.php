<?php

namespace App\Jobs;

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\CandleGapRepairs;
use App\Domain\MarketData\HistoryChanges;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RecoverMarketHistory implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 20;
    public int $maxExceptions = 1;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $requestId) {}

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(CandleGapRepairs $repairs, HistoryChanges $changes, BackfillIntelligence $builds, TickerRepository $tickers): void
    {
        $request = DB::table('history_recovery_requests')->where('request_id', $this->requestId)->first();
        if ($request === null || ! in_array($request->status, ['queued', 'scanning'], true)) {
            return;
        }
        $feed = MarketFeed::query()->with('market.exchange')->find($request->market_id);
        if (! config('history_backfill.enabled') || ! config('intelligence.enabled') || $feed === null
            || $feed->selected_period !== $request->period || ! $feed->market->subscriptions()->where('active', true)->exists()) {
            $this->state('blocked', 'Recovery needs the same active feed, selected period and enabled workers.');

            return;
        }
        $exchange = $feed->market->exchange->class;
        $symbol = $feed->market->symbol;
        $marketLock = Cache::lock('trademinator:market-feed:'.$feed->market_id, 180);
        if (! $marketLock->get()) {
            $this->release(30);

            return;
        }
        $historyLock = Cache::lock('trademinator:history-intelligence:'.ModelStore::marketKey($exchange, $symbol, $request->period), 180);
        try {
            if (! $historyLock->get()) {
                $this->release(30);

                return;
            }
            $this->state('scanning');
            if ($tickers->latestTimestamp($exchange, $symbol, $request->period) === null) {
                $this->state('blocked', 'No source candles exist. Run the market-feed collector before attempting gap recovery.');

                return;
            }
            // Uses streamHistory: verified hot and cold history are checked together.
            $repairs->scanFeed($feed);
            if ($request->retry_unavailable) {
                DB::table('candle_gap_repairs')->where('market_id', $feed->market_id)->where('period', $request->period)
                    ->whereIn('status', ['paused', 'unavailable'])
                    ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                    ->update(['status' => 'pending', 'reason' => 'owner_retry', 'next_attempt_at' => now(),
                        'empty_attempts' => 0, 'failures' => 0, 'last_error' => null, 'updated_at' => now()]);
            }
            $gaps = DB::table('candle_gap_repairs')->where('market_id', $feed->market_id)->where('period', $request->period)
                ->where('status', '!=', 'resolved')->exists();
            $history = DB::table('market_history_backfills')->where('market_id', $feed->market_id)->where('period', $request->period)->first();
            if (! $gaps && ($history === null || $history->history_revision <= $history->trained_revision)) {
                // Explicit rebuild with no known source change must take the safe full-replay path.
                $changes->record($feed->market_id, $request->period, source: 'owner_rebuild', immediate: true);
            }
            DB::table('market_history_backfills')->insertOrIgnore([
                'history_id' => (string) \Illuminate\Support\Str::uuid7(), 'market_id' => $feed->market_id,
                'period' => $request->period, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('market_history_backfills')->where('market_id', $feed->market_id)->where('period', $request->period)
                ->update(['recovery_schema' => $request->schema, 'updated_at' => now()]
                    + ($gaps ? [] : ['build_next_attempt_at' => now()]));
            $repairs->dispatchDue($exchange, $symbol, $request->period);
            $builds->dispatchDue($exchange, $symbol, $request->period);
            $this->state($gaps ? 'scan_complete_repairs_pending' : 'scan_complete_rebuild_queued');
        } catch (Throwable $error) {
            $this->state('failed', $error->getMessage());
            throw $error;
        } finally {
            $historyLock->release();
            $marketLock->release();
        }
    }

    private function state(string $status, ?string $error = null): void
    {
        DB::table('history_recovery_requests')->where('request_id', $this->requestId)->update([
            'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 1000), 'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $error): void
    {
        $this->state('failed', ($error ?? new RuntimeException('Recovery worker failed.'))->getMessage());
    }
}
