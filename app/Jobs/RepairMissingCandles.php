<?php

namespace App\Jobs;

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\MarketData\CandleGapRepairs;
use App\Domain\MarketData\CandleReconstructor;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeMetadata;
use App\Models\MarketFeed;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use ccxt\NotSupported;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RepairMissingCandles implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $gapId, public readonly string $leaseToken) {}

    public function handle(
        CandleGapRepairs $repairs,
        ExchangeRepository $exchanges,
        TickerRepository $tickers,
        ExchangeMetadata $metadata,
        BackfillIntelligence $intelligence,
    ): void {
        $state = $repairs->owned($this->gapId, $this->leaseToken)->first();
        if ($state === null) {
            return;
        }

        $feed = MarketFeed::query()->with('market.exchange')->find($state->market_id);
        if (! config('history_backfill.enabled') || $feed === null) {
            $repairs->release($this->gapId, $this->leaseToken, ['status' => 'pending', 'reason' => 'inactive_feed'], 3600);

            return;
        }
        if ($feed->selected_period !== $state->period) {
            $repairs->release($this->gapId, $this->leaseToken, [
                'status' => 'resolved', 'reason' => 'period_changed', 'next_attempt_at' => null,
            ]);

            return;
        }
        if (! $feed->market->subscriptions()->where('active', true)->exists()) {
            $repairs->release($this->gapId, $this->leaseToken, ['status' => 'pending', 'reason' => 'inactive_feed'], 3600);

            return;
        }

        $market = $feed->market;
        $exchange = $market->exchange;
        $marketLock = Cache::lock('trademinator:market-feed:'.$state->market_id, 180);
        if (! $marketLock->get()) {
            $this->release(30);

            return;
        }
        $historyLock = Cache::lock('trademinator:history-intelligence:'.hash('sha256', "$exchange->class|$market->symbol|$state->period"), 180);
        $exchangeLock = Cache::lock('trademinator:history-exchange:'.$exchange->class, 180);
        $featureLock = Cache::lock('trademinator:features:'.hash('sha256', "$exchange->class|$market->symbol|$state->period"), 180);

        try {
            if (! $historyLock->get()) {
                $this->release(30);

                return;
            }
            if (! $exchangeLock->get()) {
                $this->release(30);

                return;
            }
            if (! $featureLock->get()) {
                $this->release(30);

                return;
            }

            $metadata->assertUsable($exchange, spotOnly: false, symbol: $market->symbol);
            $exchanges->setExchange($exchange, ['timeout' => 15000], symbol: $market->symbol);
            $exchanges->prepareCandleMarket($market->symbol);
            if (! array_key_exists($state->period, $exchanges->periods())) {
                throw new NotSupported('The selected candle period is not supported by this exchange.');
            }

            $fromMs = (int) $state->from_ms;
            $toMs = (int) $state->to_ms;
            $limit = $repairs->expectedCount($state->period, $fromMs, $toMs);
            if ($limit < 1 || $limit > 100) {
                throw new InvalidArgumentException('Candle gap repair page must contain between 1 and 100 candles.');
            }
            $timeframe = new CandleTimeframe;
            $stored = $tickers->timestamps($exchange->class, $market->symbol, $state->period, $fromMs, $toMs);
            if ($repairs->missingCount($state->period, $fromMs, $toMs, $stored) === 0) {
                $repairs->release($this->gapId, $this->leaseToken, [
                    'status' => 'resolved', 'reason' => 'filled', 'next_attempt_at' => null,
                ]);

                return;
            }

            $untilMs = $timeframe->next($toMs, $state->period);
            $page = $exchanges->fetchHistoryPage($market->symbol, $state->period, $fromMs, $untilMs, $limit);
            $closed = [];
            $nowMs = now()->getTimestampMs();
            foreach ($page as $candle) {
                $timestamp = (int) ($candle['microtimestamp'] ?? -1);
                if ($timestamp >= $fromMs && $timestamp <= $toMs
                    && $timeframe->next($timestamp, $state->period) <= $nowMs) {
                    $closed[$timestamp] = $candle;
                }
            }
            ksort($closed, SORT_NUMERIC);

            if ($fromMs === $toMs && ! isset($closed[$fromMs])) {
                $reconstructed = app(CandleReconstructor::class)->reconstruct(
                    $exchanges, $exchange->class, $market->symbol, $state->period, $fromMs, $nowMs,
                );
                if ($reconstructed !== null) {
                    $closed[$fromMs] = $reconstructed;
                }
            }

            $beforeMissing = 0;
            $afterMissing = 0;
            $changed = false;
            $saved = DB::transaction(function () use (
                $repairs,
                $tickers,
                $exchange,
                $market,
                $state,
                $fromMs,
                $toMs,
                $closed,
                &$beforeMissing,
                &$afterMissing,
                &$changed,
            ): bool {
                $owned = $repairs->owned($this->gapId, $this->leaseToken);
                if ($owned->lockForUpdate()->first() === null) {
                    return false;
                }

                $before = $tickers->timestamps($exchange->class, $market->symbol, $state->period, $fromMs, $toMs);
                $beforeMissing = $repairs->missingCount($state->period, $fromMs, $toMs, $before);
                // A gap job only inserts missing candles; it must not silently revise
                // already stored candles returned in an overlapping exchange page.
                $missing = array_diff_key($closed, array_fill_keys($before, true));
                $tickers->saveTickers($exchange->class, $market->symbol, $state->period, array_values($missing));
                $after = $tickers->timestamps($exchange->class, $market->symbol, $state->period, $fromMs, $toMs);
                $afterMissing = $repairs->missingCount($state->period, $fromMs, $toMs, $after);
                $changed = $afterMissing < $beforeMissing;
                if ($changed) {
                    $recovered = array_values(array_diff($after, $before));
                    // Reconstructed writes record their own revision, including later native replacements.
                    $native = array_values(array_filter($recovered, fn ($at) => ! isset($closed[$at]['reconstruction'])));
                    if ($native !== []) {
                        $repairs->markHistoryChanged($state->market_id, $state->period, (int) min($native), (int) max($native));
                    }
                }

                return true;
            });
            if (! $saved) {
                return;
            }

            $attempts = (int) $state->attempts + 1;
            if ($beforeMissing === 0) {
                $repairs->release($this->gapId, $this->leaseToken, [
                    'status' => 'resolved', 'reason' => 'filled', 'attempts' => $attempts,
                    'failures' => 0, 'last_error' => null, 'next_attempt_at' => null,
                ]);

                return;
            }
            if ($changed) {
                $repairs->release($this->gapId, $this->leaseToken, [
                    'status' => 'resolved',
                    'reason' => isset($closed[$fromMs]['reconstruction']) ? 'reconstructed_'.$closed[$fromMs]['reconstruction']['method']
                        : ($afterMissing === 0 ? 'repaired' : 'partially_repaired'),
                    'attempts' => $attempts,
                    'empty_attempts' => 0,
                    'failures' => 0,
                    'last_error' => null,
                    'next_attempt_at' => null,
                ]);
                if ($afterMissing > 0) {
                    $repairs->reconcileInterval($feed, $fromMs, $toMs);
                }
                $intelligence->dispatchDue($exchange->class, $market->symbol, $state->period);

                return;
            }

            $emptyAttempts = (int) $state->empty_attempts + 1;
            $unavailable = $emptyAttempts >= max(1, (int) config('history_backfill.gap_empty_attempts_before_unavailable'));
            $repairs->release($this->gapId, $this->leaseToken, [
                'status' => $unavailable ? 'unavailable' : 'retrying',
                'reason' => $unavailable ? 'exchange_omitted_candles' : 'no_candle_returned',
                'attempts' => $attempts,
                'empty_attempts' => $emptyAttempts,
                'failures' => 0,
                'last_error' => null,
                'next_attempt_at' => $unavailable ? null : now()->addSeconds($repairs->emptyRetryDelay($emptyAttempts)),
            ], $repairs->emptyRetryDelay($emptyAttempts));
        } catch (Throwable $error) {
            $repairs->failure($this->gapId, $this->leaseToken, $error);
            report($error);
        } finally {
            $historyLock->release();
            $featureLock->release();
            $exchangeLock->release();
            $marketLock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(CandleGapRepairs::class)->failure(
            $this->gapId,
            $this->leaseToken,
            $exception ?? new \RuntimeException('Candle gap repair failed before completing its request.'),
        );
    }
}
