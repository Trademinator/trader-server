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
                $this->scanFeed($feed);
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

    public function scanFeed(MarketFeed $feed): int
    {
        $feed->loadMissing('market.exchange');
        if ($feed->selected_period === null) {
            return 0;
        }

        $market = $feed->market;
        $period = $feed->selected_period;
        $nowMs = now()->getTimestampMs();
        $previous = null;
        $seen = [];
        $found = 0;

        foreach ($this->tickers->streamHistory($market->exchange->class, $market->symbol, $period, 0, $nowMs) as $timestamp => $_) {
            $timestamp = (int) $timestamp;
            if ($previous !== null) {
                $firstMissing = $this->timeframe->next($previous, $period);
                if ($firstMissing < $timestamp) {
                    $found += $this->recordSequence($feed, $firstMissing, $timestamp, $nowMs, $seen);
                }
            }
            $previous = $timestamp;
        }

        if ($previous !== null) {
            $found += $this->recordSequence($feed, $this->timeframe->next($previous, $period), null, $nowMs, $seen);
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
        DB::table('candle_gap_repairs')->insertOrIgnore([
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
        DB::table('candle_gap_repairs')->where('market_id', $feed->market_id)->where('period', $period)
            ->where('from_ms', $fromMs)->where('to_ms', $toMs)->where('status', 'resolved')->update([
                'status' => 'pending', 'reason' => 'gap_reappeared', 'attempts' => 0, 'empty_attempts' => 0,
                'failures' => 0, 'last_error' => null, 'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function resolveRowsNotSeen(string $marketId, string $period, array $seen): void
    {
        $rows = DB::table('candle_gap_repairs')->where('market_id', $marketId)->where('period', $period)
            ->whereNotIn('status', ['resolved', 'queued'])->get(['gap_id', 'from_ms', 'to_ms']);
        foreach ($rows as $row) {
            if (isset($seen[$row->from_ms.':'.$row->to_ms])) {
                continue;
            }
            DB::table('candle_gap_repairs')->where('gap_id', $row->gap_id)->update([
                'status' => 'resolved', 'reason' => 'filled', 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => null, 'updated_at' => now(),
            ]);
        }
    }
}
