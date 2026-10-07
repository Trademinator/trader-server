<?php

namespace App\Domain\MarketData;

use App\Domain\Operations\ActionLog;
use App\Jobs\RepairMissingCandles;
use App\Models\MarketFeed;
use App\Repositories\TickerRepository;
use ccxt\AuthenticationError;
use ccxt\BadSymbol;
use ccxt\NetworkError;
use ccxt\NotSupported;
use ccxt\PermissionDenied;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CandleGapRepairs
{
    public function __construct(
        private readonly TickerRepository $tickers,
        private readonly CandleTimeframe $timeframe,
        private readonly ActionLog $log,
    ) {}

    public function feeds(?string $exchange = null, ?string $symbol = null, ?string $period = null): Builder
    {
        return MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market.subscriptions', fn ($query) => $query->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->whereHas('market.exchange', fn ($query) => $query->where('class', $exchange)))
            ->when($symbol !== null, fn ($query) => $query->whereHas('market', fn ($query) => $query->where('symbol', $symbol)))
            ->when($period !== null, fn ($query) => $query->where('selected_period', $period));
    }

    public function scan(?string $exchange = null, ?string $symbol = null, ?string $period = null, bool $force = false): int
    {
        $scanned = 0;
        $limit = $force ? 1000 : max(1, (int) config('history_backfill.gap_scan_markets_per_run'));
        $minutes = max(1, (int) config('history_backfill.gap_scan_interval_minutes'));

        foreach ($this->feeds($exchange, $symbol, $period)->lazyById(100, 'market_id') as $feed) {
            $key = 'trademinator:candle-gap-scan:'.$feed->market_id.':'.$feed->selected_period;
            if (! $force && ! Cache::add($key, true, now()->addMinutes($minutes))) {
                continue;
            }
            try {
                if ($this->scanFeed($feed) === null) {
                    if (! $force) {
                        Cache::forget($key);
                    }

                    continue;
                }

                $scanned++;
            } catch (Throwable $error) {
                Cache::forget($key);
                report($error);
            }
            if ($scanned >= $limit) {
                break;
            }
        }

        return $scanned;
    }

    /**
     * @return int|null Number of tracked gap pages, or null when another market-data worker owns the feed lock.
     */
    public function scanFeed(MarketFeed $feed, bool $marketLockHeld = false): ?int
    {
        $feed->loadMissing('market.exchange');
        if ($feed->selected_period === null) {
            return 0;
        }

        if ($marketLockHeld) {
            return $this->scanFeedLocked($feed);
        }

        // Gap discovery reads ticker history and mutates repair bookkeeping. Share
        // the canonical market-feed lock with collection/backfill/repair workers so
        // a scan never observes or rewrites a feed while one of them is changing it.
        $lock = Cache::lock('trademinator:market-feed:'.$feed->market_id, 720);
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->scanFeedLocked($feed);
        } finally {
            $lock->release();
        }
    }

    private function scanFeedLocked(MarketFeed $feed): int
    {
        $market = $feed->market;
        $period = (string) $feed->selected_period;
        $nowMs = now()->getTimestampMs();
        $seen = [];
        $found = 0;

        foreach ($this->tickers->missingClosedCandleRanges($market->exchange->class, $market->symbol, $period, $nowMs) as $range) {
            $found += $this->recordSequence($feed, $range['from'], $this->timeframe->next($range['to'], $period), $nowMs, $seen);
        }

        $this->resolveRowsNotSeen($feed->market_id, $period, $seen);
        $this->log->write('candles.gaps_scanned', [
            'market_id' => $feed->market_id,
            'exchange' => $market->exchange->class,
            'symbol' => $market->symbol,
            'period' => $period,
            'gaps' => $found,
            'outcome' => 'completed',
        ]);

        return $found;
    }

    public function dispatchDue(?string $exchange = null, ?string $symbol = null, ?string $period = null, ?int $limit = null): int
    {
        $limit ??= max(1, (int) config('history_backfill.gap_repair_dispatch_limit'));
        $limit = min(1000, max(1, $limit));

        $ranked = DB::table('candle_gap_repairs as gaps')
            ->join('markets', 'markets.market_id', '=', 'gaps.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->join('market_feeds as feeds', 'feeds.market_id', '=', 'gaps.market_id')
            ->select(['gaps.gap_id', 'gaps.next_attempt_at', 'gaps.to_ms', 'exchanges.exchange_id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchanges.exchange_id ORDER BY gaps.to_ms DESC, gaps.gap_id) AS exchange_rank')
            ->whereColumn('feeds.selected_period', 'gaps.period')
            ->whereIn('gaps.status', ['pending', 'retrying', 'queued'])
            ->where(fn ($query) => $query->whereNull('gaps.next_attempt_at')->orWhere('gaps.next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('gaps.lease_until')->orWhere('gaps.lease_until', '<=', now()))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('market_subscriptions')
                ->whereColumn('market_subscriptions.market_id', 'gaps.market_id')->where('active', true))
            ->when($exchange !== null, fn ($query) => $query->where('exchanges.class', $exchange))
            ->when($symbol !== null, fn ($query) => $query->where('markets.symbol', $symbol))
            ->when($period !== null, fn ($query) => $query->where('gaps.period', $period));

        $ids = DB::query()->fromSub($ranked, 'due_gaps')
            ->orderBy('exchange_rank')->orderBy('next_attempt_at')->orderByDesc('to_ms')->limit($limit)->pluck('gap_id');

        $count = 0;
        foreach ($ids as $gapId) {
            $token = (string) Str::uuid7();
            $claimed = DB::table('candle_gap_repairs')->where('gap_id', $gapId)
                ->whereIn('status', ['pending', 'retrying', 'queued'])
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->update([
                    'status' => 'queued', 'lease_token' => $token, 'lease_until' => now()->addMinutes(15), 'updated_at' => now(),
                ]);
            if (! $claimed) {
                continue;
            }
            try {
                RepairMissingCandles::dispatch((string) $gapId, $token)->onQueue(config('history_backfill.queue'));
                $count++;
            } catch (Throwable $error) {
                $this->release((string) $gapId, $token, [
                    'status' => 'retrying', 'reason' => 'dispatch_failed', 'last_error' => mb_substr($error->getMessage(), 0, 1000),
                ]);
                throw $error;
            }
        }

        return $count;
    }

    public function owned(string $id, string $token): QueryBuilder
    {
        return DB::table('candle_gap_repairs')->where('gap_id', $id)->where('lease_token', $token);
    }

    public function release(string $id, string $token, array $changes = [], int $delay = 60): void
    {
        $updated = $this->owned($id, $token)->update(array_merge([
            'lease_token' => null,
            'lease_until' => null,
            'next_attempt_at' => now()->addSeconds($delay),
            'status' => 'pending',
            'updated_at' => now(),
        ], $changes));
        if ($updated) {
            $this->log->write('candles.gap_updated', [
                'subject_id' => $id,
                'status' => $changes['status'] ?? 'pending',
                'reason' => $changes['reason'] ?? null,
                'outcome' => 'completed',
            ]);
        }
    }

    public function failure(string $id, string $token, Throwable $error): void
    {
        $row = $this->owned($id, $token)->first();
        if ($row === null) {
            return;
        }

        $this->log->write('candles.gap_failed', [
            'subject_id' => $id,
            'market_id' => $row->market_id,
            'outcome' => 'failed',
            ...$this->log->exception($error),
        ]);
        $failures = min(100, (int) $row->failures + 1);
        $permanent = $error instanceof NotSupported || $error instanceof BadSymbol
            || $error instanceof AuthenticationError || $error instanceof PermissionDenied
            || $error instanceof MarketCatalogException;
        $transient = $error instanceof NetworkError;
        $pause = $permanent || (! $transient && $failures >= max(1, (int) config('history_backfill.errors_before_pause')));

        $this->release($id, $token, [
            'status' => $pause ? 'paused' : 'retrying',
            'reason' => $permanent ? 'access_or_support' : ($pause ? 'repeated_errors' : 'request_failed'),
            'failures' => $failures,
            'last_error' => mb_substr($error->getMessage(), 0, 1000),
        ], min(3600, 60 * (2 ** min(6, $failures - 1))));
    }

    public function missingCount(string $period, int $fromMs, int $toMs, array $timestamps): int
    {
        $actual = array_fill_keys(array_map('intval', $timestamps), true);
        $missing = 0;
        for ($cursor = $fromMs; $cursor <= $toMs; $cursor = $this->timeframe->next($cursor, $period)) {
            if (! isset($actual[$cursor])) {
                $missing++;
            }
            $next = $this->timeframe->next($cursor, $period);
            if ($next <= $cursor) {
                break;
            }
        }

        return $missing;
    }

    public function expectedCount(string $period, int $fromMs, int $toMs): int
    {
        $count = 0;
        for ($cursor = $fromMs; $cursor <= $toMs; $cursor = $this->timeframe->next($cursor, $period)) {
            $count++;
            $next = $this->timeframe->next($cursor, $period);
            if ($next <= $cursor) {
                break;
            }
        }

        return $count;
    }

    public function reconcileInterval(MarketFeed $feed, int $fromMs, int $toMs): int
    {
        $period = (string) $feed->selected_period;
        $timestamps = $this->tickers->timestamps(
            $feed->market->exchange->class,
            $feed->market->symbol,
            $period,
            $fromMs,
            $toMs,
        );
        $actual = array_fill_keys($timestamps, true);
        $seen = [];
        $found = 0;
        $start = null;
        $end = null;
        $count = 0;
        $pageSize = min(100, max(1, (int) config('history_backfill.page_size')));

        for ($cursor = $fromMs; $cursor <= $toMs; $cursor = $this->timeframe->next($cursor, $period)) {
            if (! isset($actual[$cursor])) {
                $start ??= $cursor;
                $end = $cursor;
                $count++;
                if ($count >= $pageSize) {
                    $this->rememberGap($feed, $start, $end, $seen);
                    $found++;
                    $start = $end = null;
                    $count = 0;
                }
            } elseif ($start !== null) {
                $this->rememberGap($feed, $start, $end, $seen);
                $found++;
                $start = $end = null;
                $count = 0;
            }

            $next = $this->timeframe->next($cursor, $period);
            if ($next <= $cursor) {
                break;
            }
        }
        if ($start !== null) {
            $this->rememberGap($feed, $start, $end, $seen);
            $found++;
        }

        return $found;
    }

    public function markHistoryChanged(string $marketId, string $period, ?int $fromMs = null, ?int $toMs = null): void
    {
        app(HistoryChanges::class)->record($marketId, $period, $fromMs, $toMs);
    }

    /** Manual sync shares the existing retry records instead of leaving them indefinitely retrying. */
    public function recordManualResult(MarketFeed $feed, int $fromMs, int $toMs, array $before, array $after): void
    {
        $period = (string) $feed->selected_period;
        $seen = [];
        foreach ((new CandleGaps)->between(array_keys($after), $period) as $gap) {
            $this->recordSequence($feed, $gap['from'], $gap['to'] + 1, now()->getTimestampMs(), $seen);
        }
        $rows = DB::table('candle_gap_repairs')->where('market_id', $feed->market_id)->where('period', $period)
            ->where('from_ms', '>=', $fromMs)->where('to_ms', '<=', $toMs)->where('status', '!=', 'resolved')
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->get();
        foreach ($rows as $row) {
            $beforeMissing = $this->missingCount($period, $row->from_ms, $row->to_ms, array_keys($before));
            $afterMissing = $this->missingCount($period, $row->from_ms, $row->to_ms, array_keys($after));
            $partial = $afterMissing > 0 && $afterMissing < $beforeMissing;
            if ($partial) {
                $this->reconcileInterval($feed, $row->from_ms, $row->to_ms);
            }
            $resolved = $afterMissing === 0 || $partial;
            $empty = $afterMissing > 0 && $afterMissing >= $beforeMissing ? (int) $row->empty_attempts + 1 : 0;
            $unavailable = $empty >= max(1, (int) config('history_backfill.gap_empty_attempts_before_unavailable'));
            $method = $afterMissing === 0 ? ($after[$row->from_ms]['reconstruction']['method'] ?? null) : null;
            DB::table('candle_gap_repairs')->where('gap_id', $row->gap_id)
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->update([
                    'status' => $resolved ? 'resolved' : ($unavailable ? 'unavailable' : 'retrying'),
                    'reason' => $afterMissing === 0 ? ($method === null ? 'repaired' : 'reconstructed_'.$method)
                        : ($partial ? 'partially_repaired' : ($unavailable ? 'exchange_omitted_candles' : 'no_candle_returned')),
                    'attempts' => (int) $row->attempts + 1, 'empty_attempts' => $empty,
                    'failures' => 0, 'last_error' => null, 'lease_token' => null, 'lease_until' => null,
                    'next_attempt_at' => $resolved || $unavailable ? null : now()->addSeconds($this->emptyRetryDelay($empty)),
                    'updated_at' => now(),
                ]);
        }
    }

    public function emptyRetryDelay(int $emptyAttempts): int
    {
        return match ($emptyAttempts) {
            1 => 3600,
            2 => 21600,
            default => 86400,
        };
    }

    private function recordSequence(MarketFeed $feed, int $firstMissing, ?int $exclusiveEnd, int $nowMs, array &$seen): int
    {
        $period = (string) $feed->selected_period;
        $pageSize = min(100, max(1, (int) config('history_backfill.page_size')));
        $start = null;
        $end = null;
        $count = 0;
        $found = 0;
        $cursor = $firstMissing;

        while (($exclusiveEnd === null || $cursor < $exclusiveEnd)
            && $this->timeframe->next($cursor, $period) <= $nowMs) {
            $start ??= $cursor;
            $end = $cursor;
            $count++;
            if ($count >= $pageSize) {
                $this->rememberGap($feed, $start, $end, $seen);
                $found++;
                $start = $end = null;
                $count = 0;
            }
            $next = $this->timeframe->next($cursor, $period);
            if ($next <= $cursor) {
                break;
            }
            $cursor = $next;
        }
        if ($start !== null) {
            $this->rememberGap($feed, $start, $end, $seen);
            $found++;
        }

        return $found;
    }

    private function rememberGap(MarketFeed $feed, int $fromMs, int $toMs, array &$seen): void
    {
        $period = (string) $feed->selected_period;
        $key = $fromMs.':'.$toMs;
        $seen[$key] = true;

        $this->retryConcurrentWrite(function () use ($feed, $period, $fromMs, $toMs): void {
            $existing = DB::table('candle_gap_repairs')
                ->where('market_id', $feed->market_id)
                ->where('period', $period)
                ->where('from_ms', $fromMs)
                ->where('to_ms', $toMs)
                ->first(['gap_id', 'status']);

            // Avoid touching the unique key when an active repair row already
            // exists. InnoDB may otherwise wait on a worker that owns that row.
            if ($existing !== null && $existing->status !== 'resolved') {
                return;
            }

            if ($existing === null) {
                $inserted = DB::table('candle_gap_repairs')->insertOrIgnore([
                    'gap_id' => (string) Str::uuid7(),
                    'market_id' => $feed->market_id,
                    'period' => $period,
                    'from_ms' => $fromMs,
                    'to_ms' => $toMs,
                    'status' => 'pending',
                    'next_attempt_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted) {
                    return;
                }
            }

            // A concurrent insert may have won after our read. Re-open only a
            // resolved, unleased row and never overwrite an active worker lease.
            DB::table('candle_gap_repairs')
                ->where('market_id', $feed->market_id)
                ->where('period', $period)
                ->where('from_ms', $fromMs)
                ->where('to_ms', $toMs)
                ->where('status', 'resolved')
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->update([
                    'status' => 'pending', 'reason' => 'gap_reappeared', 'attempts' => 0, 'empty_attempts' => 0,
                    'failures' => 0, 'last_error' => null, 'next_attempt_at' => now(), 'updated_at' => now(),
                ]);
        });
    }

    private function resolveRowsNotSeen(string $marketId, string $period, array $seen): void
    {
        $rows = DB::table('candle_gap_repairs')->where('market_id', $marketId)->where('period', $period)
            ->whereNotIn('status', ['resolved', 'queued'])->get(['gap_id', 'from_ms', 'to_ms']);
        foreach ($rows as $row) {
            if (isset($seen[$row->from_ms.':'.$row->to_ms])) {
                continue;
            }

            $this->retryConcurrentWrite(function () use ($row): void {
                DB::table('candle_gap_repairs')->where('gap_id', $row->gap_id)
                    ->whereNotIn('status', ['resolved', 'queued'])
                    ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                    ->update([
                        'status' => 'resolved', 'reason' => 'filled', 'lease_token' => null, 'lease_until' => null,
                        'next_attempt_at' => null, 'updated_at' => now(),
                    ]);
            });
        }
    }

    private function retryConcurrentWrite(callable $callback, int $attempts = 3): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $callback();

                return;
            } catch (QueryException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if (! in_array($driverCode, [1020, 1205, 1213], true) || $attempt >= $attempts) {
                    throw $error;
                }

                usleep(random_int(10_000, 50_000) * $attempt);
            }
        }
    }
}
