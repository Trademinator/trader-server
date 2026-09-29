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
            'invalid_candles' => 0, 'last_closed_at_ms' => null, 'checked_at_ms' => $now];
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return $data;
        }
        $limit = max(2, min(720, $limit));
        $rows = Ticker::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)
            ->where('period', $period)->where('microtimestamp', '<=', $now)
            ->orderByDesc('microtimestamp')->limit($limit + 1)->get(['microtimestamp', 'payload'])->reverse();
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
}
