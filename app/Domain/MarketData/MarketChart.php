<?php

namespace App\Domain\MarketData;

use App\Models\Market;
use App\Models\MarketSignal;
use App\Models\Ticker;

final class MarketChart
{
    public function __construct(private CandleTimeframe $timeframe) {}

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
        $rows = Ticker::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)
            ->where('period', $period)->where('microtimestamp', '<=', $now)
            ->orderByDesc('microtimestamp')->limit($limit + 1)->get(['microtimestamp', 'payload']);
        $data['has_older'] = $rows->count() > $limit;
        $rows = $rows->reverse();
        $last = null;
        foreach ($rows as $row) {
            $time = (int) $row->microtimestamp;
            $closeAt = $this->timeframe->next($time, $period);
            if ($closeAt > $now) {
                continue;
            }
            $raw = json_decode($row->payload, true);
            $valid = $time >= 0 && $time % 1000 === 0;
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                $valid = $valid && is_numeric($raw[$field] ?? null) && is_finite((float) $raw[$field]);
            }
            if (! $valid || min($raw['open'], $raw['low'], $raw['close']) <= 0 || $raw['volume'] < 0
                || $raw['high'] < max($raw['open'], $raw['close']) || $raw['low'] > min($raw['open'], $raw['close'])) {
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
        $query = Ticker::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)
            ->where('period', $period)->where('microtimestamp', '<', $untilMs);

        if ($direction === 'older') {
            $query->where('microtimestamp', '<', $anchorMs)->orderByDesc('microtimestamp');
        } elseif ($direction === 'newer') {
            $query->where('microtimestamp', '>', $anchorMs)->orderBy('microtimestamp');
        } else {
            $query->orderBy('microtimestamp');
        }

        $rows = $query->limit($limit + 1)->get(['microtimestamp', 'payload']);
        $extra = $rows->count() > $limit;
        $rows = $rows->take($limit);

        if ($direction === 'older') {
            $rows = $rows->reverse();
            $data['has_older'] = $extra;
            $data['has_newer'] = true;
        } elseif ($direction === 'newer') {
            $data['has_older'] = true;
            $data['has_newer'] = $extra;
        } else {
            $data['has_newer'] = $extra;
        }

        $last = null;
        foreach ($rows as $row) {
            $time = (int) $row->microtimestamp;
            if ($this->timeframe->next($time, $period) > $untilMs) {
                continue;
            }
            $raw = json_decode($row->payload, true);
            $valid = $time >= 0 && $time % 1000 === 0;
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                $valid = $valid && is_numeric($raw[$field] ?? null) && is_finite((float) $raw[$field]);
            }
            if (! $valid || min($raw['open'], $raw['low'], $raw['close']) <= 0 || $raw['volume'] < 0
                || $raw['high'] < max($raw['open'], $raw['close']) || $raw['low'] > min($raw['open'], $raw['close'])) {
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

}
