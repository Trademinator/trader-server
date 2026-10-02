<?php

namespace App\Domain\MarketData;

use App\Models\MarketFeed;
use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Trademinator\Time\periods_to_seconds;

final class CandlePeriodReevaluation
{
    public function __construct(
        private readonly ExchangeRepository $exchanges,
        private readonly TickerRepository $tickers,
        private readonly CandleQuality $quality,
        private readonly CandlePeriodViability $viability,
        private readonly MarketHistoryBackfill $history,
        private readonly HistoryDepth $depth,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(MarketFeed $feed, bool $dryRun = false, bool $force = false): array
    {
        $feed->loadMissing('market.exchange');
        $market = $feed->market;
        $exchange = $market->exchange;
        $version = max(1, (int) config('candle_period.selection_version', 2));

        if (! $market->subscriptions()->where('active', true)->exists()) {
            return $this->result($feed, 'inactive', 'Market has no active subscriptions.');
        }
        if (! $force && ! $dryRun && $feed->selection_next_attempt_at?->isFuture()) {
            return $this->result($feed, 'deferred', 'The next automatic candle-period evaluation is not due yet.');
        }

        $lock = Cache::lock('trademinator:candle-period-evaluation:'.$feed->market_id, 300);
        if (! $lock->get()) {
            return $this->result($feed, 'busy', 'Another candle-period evaluation is already running.');
        }

        try {
            $this->exchanges->setExchange($exchange, symbol: $market->symbol);
            $configured = array_values(array_filter((array) config('candle_period.periods', CandleTimeframe::SUPPORTED), 'is_string'));
            $supported = array_values(array_intersect($configured, array_keys($this->exchanges->periods()), CandleTimeframe::SUPPORTED));
            usort($supported, fn (string $a, string $b): int => periods_to_seconds($a) <=> periods_to_seconds($b));
            if ($supported === []) {
                return $this->defer($feed, $dryRun, 'no_supported_period', 'The exchange has no Trademinator-supported candle periods.');
            }

            $metadata = $this->exchanges->candleMarketMetadata($market->symbol);
            $takerFee = MarketCatalog::takerFee($metadata['taker'] ?? null, $exchange->class);
            if ($takerFee === null) {
                return $this->defer($feed, $dryRun, 'fee_unavailable', 'A published one-side taker fee is required for economic candle-period evaluation.');
            }

            $qualityThreshold = max(0.0, min(1.0, (float) config('candle_period.quality_threshold', 0.7)));
            $minimumCoverage = max(0.0, min(1.0, (float) config('candle_period.minimum_coverage', 0.8)));
            $minimumCandles = max(1, (int) config('candle_period.minimum_candles', 50));
            $minimumActionRatio = max(0.0, min(1.0, (float) config('candle_period.minimum_action_ratio', 0.01)));
            $primaryDays = max(1, (int) config('candle_period.evaluation_days', 7));
            $fallbackDays = max(1, (int) config('candle_period.fallback_days', 7));
            $windows = [$primaryDays, $primaryDays + $fallbackDays];
            $toMs = now()->getTimestampMs();
            $attempts = [];

            foreach ($supported as $period) {
                foreach ($windows as $windowIndex => $days) {
                    $fromMs = max(0, $toMs - $days * 86_400_000);
                    $candles = $this->window($exchange->class, $market->symbol, $period, $fromMs, $toMs);
                    $historyState = $this->historyState($feed, $period, $fromMs, $candles);

                    if ($historyState === 'pending') {
                        $attempts[] = ['period' => $period, 'days' => $days, 'status' => 'needs_backfill'];
                        if (! $dryRun) {
                            $queued = $this->history->dispatchCandidate($feed, $period, $fromMs);
                            $this->scheduleRetry($feed, (int) config('candle_period.backfill_retry_minutes', 15));

                            $reason = $queued
                                ? "Queued {$period} history back to {$days} days for evaluation."
                                : "{$period} history is not complete yet; waiting for the existing backfill.";

                            return $this->result($feed, 'pending', $this->withPreviousEconomicFailure($reason, $attempts), $attempts);
                        }

                        return $this->result($feed, 'needs_backfill',
                            $this->withPreviousEconomicFailure("{$period} needs history back to {$days} days.", $attempts), $attempts);
                    }
                    if ($historyState === 'unavailable') {
                        $attempts[] = ['period' => $period, 'days' => $days, 'status' => 'history_unavailable'];
                        unset($candles);
                        break;
                    }

                    $quality = $this->quality->choose([$period => $candles], (float) $market->tick_size,
                        $qualityThreshold, $minimumCandles, $minimumCoverage);
                    if ($quality === null) {
                        $attempts[] = ['period' => $period, 'days' => $days, 'status' => 'quality_failed'];
                        unset($candles);
                        // A 4h period has only 42 candles in seven days. The same
                        // 7+7 fallback also gives quality a fair minimum sample.
                        if ($windowIndex === 0) {
                            continue;
                        }
                        break;
                    }

                    $sampleFromMs = (int) $candles[0]['microtimestamp'];
                    $sampleToMs = (int) $candles[array_key_last($candles)]['microtimestamp'];
                    $economic = $this->viability->evaluate($candles, $takerFee, $minimumActionRatio);
                    $attempts[] = [
                        'period' => $period,
                        'days' => $days,
                        'status' => $economic['passes'] ? 'passed' : 'economic_failed',
                        'buy_ratio' => $economic['buy_ratio'],
                        'sell_ratio' => $economic['sell_ratio'],
                        'buy' => $economic['buy'],
                        'sell' => $economic['sell'],
                        'hold' => $economic['hold'],
                    ];
                    if (! $economic['passes']) {
                        unset($candles);
                        // First miss: extend the same candidate another seven
                        // days backward before rejecting it for a larger period.
                        if ($windowIndex === 0) {
                            continue;
                        }
                        break;
                    }

                    unset($candles);
                    $probe = $this->depth->depthProbe($period, $toMs);
                    if (! $this->exchanges->hasHistoricalData($market->symbol, $period,
                        $probe['from'], $probe['until'], $probe['limit'])) {
                        $attempts[array_key_last($attempts)]['status'] = 'depth_failed';
                        break;
                    }

                    return $this->accept($feed, $period, $version, $days, $sampleFromMs, $sampleToMs,
                        $quality['quality'], $economic, $qualityThreshold, $minimumCoverage, $dryRun, $attempts);
                }
            }

            if (! $dryRun) {
                $this->scheduleRetry($feed, (int) config('candle_period.no_candidate_retry_minutes', 360));
            }

            return $this->result($feed, 'no_candidate', 'No supported period passed quality, history depth and the BUY/SELL density gate.', $attempts);
        } finally {
            $lock->release();
        }
    }

    /** @return list<array<string, mixed>> */
    private function window(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): array
    {
        $timeframe = new CandleTimeframe;
        $candles = [];
        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $fromMs, $toMs) as $candle) {
            if ($timeframe->next((int) $candle['microtimestamp'], $period) <= $toMs) {
                $candles[] = [
                    'microtimestamp' => (int) $candle['microtimestamp'],
                    'open' => $candle['open'] ?? null,
                    'high' => $candle['high'] ?? null,
                    'low' => $candle['low'] ?? null,
                    'close' => $candle['close'] ?? null,
                    'volume' => $candle['volume'] ?? null,
                ];
            }
        }

        return $candles;
    }

    /** @param list<array<string, mixed>> $candles */
    private function historyState(MarketFeed $feed, string $period, int $fromMs, array $candles): string
    {
        $timeframe = new CandleTimeframe;
        if ($candles !== [] && (int) $candles[0]['microtimestamp'] <= $timeframe->next($fromMs, $period)) {
            return 'ready';
        }

        $state = DB::table('market_history_backfills')->where('market_id', $feed->market_id)
            ->where('period', $period)->first();
        if ($state === null) {
            return 'pending';
        }
        if ($state->before_ms !== null && (int) $state->before_ms <= $fromMs) {
            return 'ready';
        }
        if ($state->status === 'paused') {
            return 'unavailable';
        }
        if ($state->status === 'complete' && $state->reason !== 'target_reached') {
            return 'unavailable';
        }

        return 'pending';
    }

    private function accept(MarketFeed $feed, string $period, int $version, int $days,
        int $sampleFromMs, int $sampleToMs,
        array $quality, array $economic, float $threshold, float $coverage, bool $dryRun, array $attempts): array
    {
        $current = $feed->selected_period;
        if ($dryRun) {
            return $this->result($feed, $current === $period ? 'unchanged' : 'would_switch',
                $current === $period ? "{$period} remains the selected period." : "Would switch {$current} to {$period}.",
                $attempts, $period, $days, $economic);
        }

        DB::transaction(function () use ($feed, $period, $version, $sampleFromMs, $sampleToMs, $quality, $economic, $threshold, $coverage): void {
            $locked = MarketFeed::query()->whereKey($feed->market_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'selected_period' => $period,
                'selection_version' => $version,
                'selection_checked_at' => now(),
                'selection_next_attempt_at' => null,
                'next_pull_at' => now(),
            ])->save();

            DB::table('candle_period_selections')->insert([
                'selection_id' => (string) Str::uuid7(),
                'exchange' => $feed->market->exchange->class,
                'symbol' => $feed->market->symbol,
                'period' => $period,
                'sample_from_ms' => $sampleFromMs,
                'sample_to_ms' => $sampleToMs,
                'threshold' => $threshold,
                'minimum_coverage' => $coverage,
                'quality' => json_encode([...$quality, 'economic' => $economic, 'selection_version' => $version], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            DB::table('market_history_backfills')->where('market_id', $feed->market_id)->where('period', $period)
                ->whereNotNull('target_start_ms')->update([
                    'target_start_ms' => null,
                    'status' => 'active',
                    'reason' => null,
                    'failures' => 0,
                    'last_error' => null,
                    'next_attempt_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $feed->refresh();

        return $this->result($feed, $current === $period ? 'unchanged' : 'switched',
            $current === $period ? "{$period} remains the selected period." : "Selected period changed from {$current} to {$period}.",
            $attempts, $period, $days, $economic);
    }

    private function defer(MarketFeed $feed, bool $dryRun, string $status, string $reason): array
    {
        if (! $dryRun) {
            $this->scheduleRetry($feed, (int) config('candle_period.no_candidate_retry_minutes', 360));
        }

        return $this->result($feed, $status, $reason);
    }

    private function scheduleRetry(MarketFeed $feed, int $minutes): void
    {
        MarketFeed::query()->whereKey($feed->market_id)->update([
            'selection_checked_at' => now(),
            'selection_next_attempt_at' => now()->addMinutes(max(1, $minutes)),
        ]);
        $feed->refresh();
    }

    /** @param list<array<string, mixed>> $attempts */
    private function withPreviousEconomicFailure(string $reason, array $attempts): string
    {
        $failed = $this->latestEconomicAttempt($attempts, true);
        if ($failed === null) {
            return $reason;
        }

        return sprintf(
            '%s failed %d-day viability (BUY %.2f%%, SELL %.2f%%); %s',
            $failed['period'],
            $failed['days'],
            (float) $failed['buy_ratio'] * 100,
            (float) $failed['sell_ratio'] * 100,
            lcfirst($reason),
        );
    }

    /** @param list<array<string, mixed>> $attempts
     * @return array<string, mixed>|null
     */
    private function latestEconomicAttempt(array $attempts, bool $failedOnly = false): ?array
    {
        for ($index = count($attempts) - 1; $index >= 0; $index--) {
            $attempt = $attempts[$index];
            if (! array_key_exists('buy_ratio', $attempt) || ! array_key_exists('sell_ratio', $attempt)) {
                continue;
            }
            if ($failedOnly && ($attempt['status'] ?? null) !== 'economic_failed') {
                continue;
            }

            return $attempt;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function result(MarketFeed $feed, string $status, string $reason, array $attempts = [],
        ?string $period = null, ?int $days = null, ?array $economic = null): array
    {
        $lastEconomic = $economic ?? $this->latestEconomicAttempt($attempts);

        return [
            'market_id' => $feed->market_id,
            'exchange' => $feed->market->exchange->class,
            'symbol' => $feed->market->symbol,
            'current_period' => $feed->selected_period,
            // Result means a candidate that actually passed. Keep it null while
            // evaluation is pending, blocked or waiting for candidate history.
            'selected_period' => $period,
            'status' => $status,
            'reason' => $reason,
            'window_days' => $days ?? ($lastEconomic['days'] ?? null),
            'buy_ratio' => $lastEconomic['buy_ratio'] ?? null,
            'sell_ratio' => $lastEconomic['sell_ratio'] ?? null,
            'attempts' => $attempts,
        ];
    }
}
