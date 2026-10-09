<?php

namespace App\Domain\MarketData;

use App\Models\Market;
use App\Models\MarketSignal;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;

final class MarketChart
{
    public function __construct(private CandleTimeframe $timeframe, private TickerRepository $tickers) {}

    public function data(Market $market, int $limit = 360): array
    {
        $period = $market->feed?->selected_period;
        $now = now()->getTimestampMs();
        $data = ['period' => $period, 'series' => [], 'signals' => [], 'stale' => true, 'gaps' => 0,
            'invalid_candles' => 0, 'last_closed_at_ms' => null, 'checked_at_ms' => $now,
            'has_older' => false, 'has_newer' => false];
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return $data;
        }
        $limit = max(2, min(720, $limit));
        $timestamps = $this->tickers->pageTimestamps(
            $market->exchange->class, $market->symbol, $period, 'latest', null, $now, $limit + 1
        );
        $data['has_older'] = count($timestamps) > $limit;
        $rows = $this->rows($market, $period, $timestamps);

        $last = null;
        foreach ($rows as $time => $raw) {
            $closeAt = $this->timeframe->next($time, $period);
            if ($closeAt > $now) {
                continue;
            }
            if (! $this->valid($time, $raw)) {
                $data['invalid_candles']++;

                continue;
            }
            if ($last !== null && $this->timeframe->next($last, $period) !== $time) {
                $data['gaps']++;
            }
            $last = $time;
            $data['series'][] = ['time' => intdiv($time, 1000), 'open' => (float) $raw['open'], 'high' => (float) $raw['high'],
                'low' => (float) $raw['low'], 'close' => (float) $raw['close'], 'volume' => (float) $raw['volume']];
            $data['last_closed_at_ms'] = $closeAt;
        }
        $data['series'] = array_slice($data['series'], -$limit);
        if ($data['last_closed_at_ms'] !== null) {
            $staleAt = $data['last_closed_at_ms'];
            for ($i = 0; $i < config('intelligence.max_signal_age_periods'); $i++) {
                $staleAt = $this->timeframe->next($staleAt, $period);
            }
            $data['stale'] = $now >= $staleAt;
        }
        if ($data['series'] !== [] && $limit > 60) {
            $firstOpen = $data['series'][0]['time'] * 1000;
            $lastOpen = $data['series'][array_key_last($data['series'])]['time'] * 1000;
            $data['signals'] = $this->signals($market, $period,
                $this->timeframe->next($firstOpen, $period),
                $this->timeframe->next($lastOpen, $period), 900);
        }

        return $data;
    }

    public function page(Market $market, string $direction, ?int $anchorMs, int $untilMs, int $limit = 90): array
    {
        $period = $market->feed?->selected_period;
        $data = ['period' => $period, 'series' => [], 'signals' => [], 'gaps' => 0, 'invalid_candles' => 0,
            'checked_at_ms' => now()->getTimestampMs(), 'has_older' => false, 'has_newer' => false];
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return $data;
        }

        $limit = max(2, min(360, $limit));
        $pageDirection = in_array($direction, ['older', 'newer'], true) ? $direction : 'initial';
        $timestamps = $this->tickers->pageTimestamps(
            $market->exchange->class, $market->symbol, $period, $pageDirection, $anchorMs, $untilMs - 1, $limit + 1
        );
        $extra = count($timestamps) > $limit;

        if ($pageDirection === 'older') {
            $timestamps = array_slice($timestamps, -$limit);
            $data['has_older'] = $extra;
            $data['has_newer'] = true;
        } elseif ($pageDirection === 'newer') {
            $timestamps = array_slice($timestamps, 0, $limit);
            $data['has_older'] = true;
            $data['has_newer'] = $extra;
        } else {
            $timestamps = array_slice($timestamps, 0, $limit);
            $data['has_newer'] = $extra;
        }

        $last = null;
        foreach ($this->rows($market, $period, $timestamps) as $time => $raw) {
            if ($this->timeframe->next($time, $period) > $untilMs) {
                continue;
            }
            if (! $this->valid($time, $raw)) {
                $data['invalid_candles']++;

                continue;
            }
            if ($last !== null && $this->timeframe->next($last, $period) !== $time) {
                $data['gaps']++;
            }
            $last = $time;
            $data['series'][] = ['time' => intdiv($time, 1000), 'open' => (float) $raw['open'], 'high' => (float) $raw['high'],
                'low' => (float) $raw['low'], 'close' => (float) $raw['close'], 'volume' => (float) $raw['volume']];
        }

        if ($data['series'] !== []) {
            $from = $data['series'][0]['time'] * 1000;
            $data['signals'] = $this->signals($market, $period,
                $this->timeframe->next($from, $period),
                min($this->timeframe->next($data['series'][array_key_last($data['series'])]['time'] * 1000, $period), $untilMs),
                900);
        }

        return $data;
    }

    /**
     * The historical candle itself, not a transient UI state, is the source
     * of truth. Only select JSON metadata needed by the chart leaves the Server.
     */
    private function signals(Market $market, string $period, int $fromCloseMs, int $toCloseMs, int $limit): array
    {
        if ($toCloseMs < $fromCloseMs) {
            return [];
        }

        return MarketSignal::query()->where('market_id', $market->market_id)
            ->where('period', $period)->whereBetween('decision_at_ms', [$fromCloseMs, $toCloseMs])
            ->where('recorded_at_ms', '<=', now()->getTimestampMs())
            ->whereIn('reason', ['supported', 'degraded_action_only', 'degraded_outcome_only'])
            ->orderBy('decision_at_ms')->orderBy('recorded_at_ms')->orderBy('market_signal_id')
            ->limit($limit)->get(['market_signal_id', 'recorded_at_ms', 'decision_at_ms',
                'action', 'reason', 'payload'])
            ->map(function (MarketSignal $signal) use ($period): array {
                $payload = $signal->payload ?? [];
                $action = $payload['action_knn'] ?? $payload['scoring']['action'] ?? [];
                // Exclude optional CoinGecko fusion: this overlay is specifically Outcome KNN.
                $outcome = $payload['scoring']['outcome_core'] ?? $payload['outcome_knn'] ?? [];
                $sourceOpen = $this->timeframe->previous((int) $signal->decision_at_ms, $period);
                $horizon = max(0, min(512, (int) ($payload['horizon_candles'] ?? 0)));
                $endOpen = $sourceOpen;
                for ($i = 0; $i < $horizon; $i++) {
                    $endOpen = $this->timeframe->next($endOpen, $period);
                }

                return [
                    'id' => $signal->getKey(),
                    'recorded_at_ms' => $signal->recorded_at_ms,
                    'decision_at_ms' => $signal->decision_at_ms,
                    'action' => $signal->action, 'reason' => $signal->reason,
                    'source_time' => intdiv($sourceOpen, 1000),
                    'horizon_candles' => $horizon,
                    'horizon_end_time' => $horizon ? intdiv($endOpen, 1000) : null,
                    'action_prediction' => $action['action'] ?? null,
                    'action_reason' => $action['reason'] ?? null,
                    'outcome_prediction' => $outcome['outcome'] ?? null,
                    'outcome_reason' => $outcome['reason'] ?? null,
                ];
            })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Market $market, string $period, array $timestamps): array
    {
        if ($timestamps === []) {
            return [];
        }

        // Shared market OHLCV, never user-specific training labels or Client reports.
        // Recent candles get a short TTL so exchange repairs surface promptly.
        $key = 'trademinator:chart:ohlcv:v1:'.hash('sha256', json_encode([
            $market->market_id, $market->exchange->class, $market->symbol,
            $period, $timestamps,
        ], JSON_THROW_ON_ERROR));
        $ttl = max($timestamps) < now()->getTimestampMs() - 86_400_000 ? 600 : 45;

        return Cache::remember($key, $ttl, function () use ($market, $period, $timestamps): array {
            $wanted = array_fill_keys($timestamps, true);
            $rows = [];
            foreach ($this->tickers->streamHistory(
                $market->exchange->class, $market->symbol, $period,
                min($timestamps), max($timestamps)
            ) as $timestamp => $payload) {
                if (isset($wanted[$timestamp])) {
                    $rows[$timestamp] = $payload;
                }
            }

            return $rows;
        });
    }

    private function valid(int $time, array $raw): bool
    {
        $valid = $time >= 0 && $time % 1000 === 0;
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            $valid = $valid && is_numeric($raw[$field] ?? null) && is_finite((float) $raw[$field]);
        }

        return $valid && min($raw['open'], $raw['low'], $raw['close']) > 0 && $raw['volume'] >= 0
            && $raw['high'] >= max($raw['open'], $raw['close']) && $raw['low'] <= min($raw['open'], $raw['close']);
    }
}
