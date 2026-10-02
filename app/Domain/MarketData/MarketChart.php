<?php

namespace App\Domain\MarketData;

use App\Models\Market;
use App\Models\MarketSignal;
use App\Repositories\TickerRepository;

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
            $data['signals'] = MarketSignal::query()->where('market_id', $market->market_id)->where('period', $period)
                ->where('is_change', true)->where('recorded_at_ms', '>=', $data['series'][0]['time'] * 1000)
                ->where('recorded_at_ms', '<=', $now)->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')
                ->limit(200)->get(['market_signal_id', 'recorded_at_ms', 'decision_at_ms', 'action', 'reason'])->reverse()->map(fn (MarketSignal $signal): array => [
                    'id' => $signal->getKey(), 'recorded_at_ms' => $signal->recorded_at_ms,
                    'decision_at_ms' => $signal->decision_at_ms, 'action' => $signal->action, 'reason' => $signal->reason,
                ])->values()->all();
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
            $to = $this->timeframe->next($data['series'][array_key_last($data['series'])]['time'] * 1000, $period);
            $data['signals'] = MarketSignal::query()->where('market_id', $market->market_id)->where('period', $period)
                ->where('is_change', true)->where('recorded_at_ms', '>=', $from)->where('recorded_at_ms', '<=', min($to, $untilMs))
                ->orderBy('recorded_at_ms')->orderBy('market_signal_id')->limit(200)
                ->get(['market_signal_id', 'recorded_at_ms', 'decision_at_ms', 'action', 'reason'])->map(fn (MarketSignal $signal): array => [
                    'id' => $signal->getKey(), 'recorded_at_ms' => $signal->recorded_at_ms,
                    'decision_at_ms' => $signal->decision_at_ms, 'action' => $signal->action, 'reason' => $signal->reason,
                ])->values()->all();
        }

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Market $market, string $period, array $timestamps): array
    {
        if ($timestamps === []) {
            return [];
        }

        $wanted = array_fill_keys($timestamps, true);
        $rows = [];
        foreach ($this->tickers->streamHistory(
            $market->exchange->class,
            $market->symbol,
            $period,
            min($timestamps),
            max($timestamps)
        ) as $timestamp => $payload) {
            if (isset($wanted[$timestamp])) {
                $rows[$timestamp] = $payload;
            }
        }

        return $rows;
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
