<?php

namespace App\Jobs;

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketHistoryBackfill;
use App\Models\MarketFeed;
use App\Models\Ticker;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\NotSupported;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

final class BackfillMarketHistory implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $historyId, public readonly string $leaseToken) {}

    public function handle(MarketHistoryBackfill $history, ExchangeRepository $exchanges,
        TickerRepository $tickers, ExchangeMetadata $metadata, BackfillIntelligence $intelligence): void
    {
        $state = $history->owned($this->historyId, $this->leaseToken)->first();
        if ($state === null) {
            return;
        }
        $feed = MarketFeed::query()->with('market.exchange')->find($state->market_id);
        if (! config('history_backfill.enabled') || $feed === null || $feed->selected_period !== $state->period
            || ! $feed->market->subscriptions()->where('active', true)->exists()) {
            $history->release($this->historyId, $this->leaseToken, ['status' => 'idle', 'reason' => 'inactive_feed']);

            return;
        }
        $market = $feed->market;
        $exchange = $market->exchange;
        $marketLock = Cache::lock('trademinator:market-feed:'.$state->market_id, 180);
        if (! $marketLock->get()) {
            $this->release(30);

            return;
        }
        $exchangeLock = Cache::lock('trademinator:history-exchange:'.$exchange->class, 180);
        $featureLock = Cache::lock('trademinator:features:'.hash('sha256', "$exchange->class|$market->symbol|$state->period"), 180);
        try {
            if (! $exchangeLock->get()) {
                $this->release(30);

                return;
            }
            if (! $featureLock->get()) {
                $this->release(30);

                return;
            }
            $deadline = microtime(true) + min(60, max(1, (int) config('history_backfill.max_seconds')));
            $metadata->assertUsable($exchange, spotOnly: false);
            $timeframe = new CandleTimeframe;
            if ($state->before_ms === null) {
                $oldest = Ticker::query()->where('exchange', $exchange->class)->where('symbol', $market->symbol)
                    ->where('period', $state->period)->min('microtimestamp');
                if ($oldest === null || $timeframe->next((int) $oldest, $state->period) > now()->getTimestampMs()) {
                    $history->release($this->historyId, $this->leaseToken, ['reason' => 'waiting_for_live_history']);

                    return;
                }
                $state->before_ms = $state->oldest_candle_ms = (int) $oldest;
                $history->owned($this->historyId, $this->leaseToken)->update([
                    'before_ms' => $oldest, 'oldest_candle_ms' => $oldest,
                ]);
            }
            $exchanges->setExchange($exchange, ['timeout' => 15000]);
            $exchanges->prepareCandleMarket($market->symbol);
            if (! array_key_exists($state->period, $exchanges->periods())) {
                throw new NotSupported('The selected candle period is not supported by this exchange.');
            }
            $size = (int) config('history_backfill.page_size');
            if ($size < 1 || $size > 100) {
                throw new InvalidArgumentException('History page size must be between 1 and 100 candles.');
            }
            $requests = min(20, max(1, (int) config('history_backfill.requests_per_job')));
            for ($request = 0; $request < $requests && microtime(true) < $deadline; $request++) {
                $state = $history->owned($this->historyId, $this->leaseToken)->first();
                if ($state === null) {
                    return;
                }
                if ((int) $state->before_ms <= 0) {
                    $history->release($this->historyId, $this->leaseToken, ['status' => 'complete', 'reason' => 'unix_epoch']);

                    return;
                }
                if ($state->window_start_ms === null) {
                    $end = (int) $state->before_ms;
                    $start = max(0, min($end - 86400000, $timeframe->previous($end, $state->period)));
                    $history->owned($this->historyId, $this->leaseToken)->update([
                        'window_start_ms' => $start, 'window_end_ms' => $end, 'next_since_ms' => $start, 'window_rows' => 0,
                    ]);
                    $state = $history->owned($this->historyId, $this->leaseToken)->first();
                }
                $since = (int) $state->next_since_ms;
                $until = $since;
                for ($i = 0; $i < $size && $until < $state->window_end_ms; $i++) {
                    $until = $timeframe->next($until, $state->period);
                }
                $until = min($until, (int) $state->window_end_ms);
                $page = $exchanges->fetchHistoryPage($market->symbol, $state->period, $since, $until, $size);
                $closed = [];
                foreach ($page as $candle) {
                    $timestamp = (int) $candle['microtimestamp'];
                    if ($timestamp >= $since && $timestamp < $until
                        && $timeframe->next($timestamp, $state->period) <= now()->getTimestampMs()) {
                        $closed[$timestamp] = $candle;
                    }
                }
                ksort($closed, SORT_NUMERIC);
                // A short exchange page may still have later candles. Resume after
                // its last candle; only an empty/out-of-range page scans to the bound.
                $next = $closed === [] ? $until : min($until, $timeframe->next(array_key_last($closed), $state->period));
                $changes = $this->progress($state, $closed, $next);
                $saved = DB::transaction(function () use ($history, $tickers, $exchange, $market, $state, $closed, $changes): bool {
                    $owned = $history->owned($this->historyId, $this->leaseToken);
                    if ($owned->lockForUpdate()->first() === null) {
                        return false;
                    }
                    $tickers->saveTickers($exchange->class, $market->symbol, $state->period, array_values($closed));
                    $owned->update($changes);

                    return true;
                });
                if (! $saved) {
                    return;
                }
                if ($changes['status'] === 'paused' || $changes['window_start_ms'] === null) {
                    $history->release($this->historyId, $this->leaseToken, ['status' => $changes['status']]);
                    $intelligence->dispatchDue($exchange->class, $market->symbol, $state->period);

                    return;
                }
            }
            $history->release($this->historyId, $this->leaseToken);
        } catch (Throwable $error) {
            $history->failure($this->historyId, $this->leaseToken, $error);
            report($error);
        } finally {
            $featureLock->release();
            $exchangeLock->release();
            $marketLock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MarketHistoryBackfill::class)->failure($this->historyId, $this->leaseToken,
            $exception ?? new \RuntimeException('History worker failed before completing its page.'));
    }

    /** @param array<int, array<string, mixed>> $candles */
    private function progress(stdClass $state, array $candles, int $next): array
    {
        $rows = (int) $state->window_rows + count($candles);
        $finished = $next >= $state->window_end_ms;
        $empty = $finished ? ($rows === 0 ? $state->empty_windows + 1 : 0) : $state->empty_windows;
        $paused = $finished && $empty >= max(1, config('history_backfill.empty_windows_before_pause'));

        return [
            'before_ms' => $finished ? $state->window_start_ms : $state->before_ms,
            'oldest_candle_ms' => $candles === [] ? $state->oldest_candle_ms
                : min((int) $state->oldest_candle_ms, array_key_first($candles)),
            'window_start_ms' => $finished ? null : $state->window_start_ms,
            'window_end_ms' => $finished ? null : $state->window_end_ms,
            'next_since_ms' => $finished ? null : $next, 'window_rows' => $finished ? 0 : $rows,
            'candles_received' => $state->candles_received + count($candles),
            'history_revision' => $state->history_revision + ($finished && $rows > 0 ? 1 : 0),
            'empty_windows' => $empty, 'failures' => 0, 'last_error' => null,
            'status' => $paused ? 'paused' : 'active', 'reason' => $paused ? 'no_older_data' : null,
            'updated_at' => now(),
        ];
    }
}
