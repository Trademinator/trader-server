<?php

namespace App\Domain\MarketSuggestions;

use App\Domain\MarketData\CandleTimeframe;
use App\Models\Ticker;

use function Trademinator\Time\periods_to_seconds;

final class CandleEvidence
{
    public function inspect(string $exchange, string $symbol, ?string $feedPeriod, string $horizon, bool $inverse = false, bool $includeSeries = false): array
    {
        $hours = match ($horizon) {
            'hours' => 6, 'days' => 72, 'weeks' => 336, default => 168
        };
        $sample = ['known' => false, 'message' => 'Insufficient stored history for your holding horizon.',
            'last_close' => null, 'period' => null, 'candles' => 0, 'from' => null, 'through' => null,
            'last_closed_at' => null, 'age_seconds' => null, 'stale' => false, 'continuous' => null,
            'window_hours' => $hours, 'window_candles' => null, 'effective_window_hours' => null,
            'required_candles' => 30, 'inverse' => $inverse];
        if ($includeSeries) {
            $sample['series'] = [];
        }
        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol);
        $period = (clone $query)->where('period', '1d')->exists() ? '1d' : $feedPeriod;
        // Fixed intervals only, so spacing and holding-window comparisons are exact.
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || str_contains((string) $period, 'M') || $period === '1y') {
            return $sample;
        }
        $step = periods_to_seconds($period) * 1000;
        $window = max(1, (int) ceil($hours * 3600000 / $step));
        $sample = array_replace($sample, ['period' => $period, 'window_candles' => $window,
            'effective_window_hours' => $window * $step / 3600000, 'required_candles' => max(30, $window * 3 + 1)]);
        $nowMs = now()->getTimestamp() * 1000;
        $rows = $query->where('period', $period)->where('microtimestamp', '<=', $nowMs - $step)
            ->orderByDesc('microtimestamp')->limit(max(30, min(2000, (int) config('market_suggestions.candle_limit', 720))))
            ->get(['microtimestamp', 'payload'])->reverse();
        $closes = [];
        $timestamps = [];
        $series = [];
        $zeroVolume = 0;
        foreach ($rows as $row) {
            $raw = json_decode($row->payload, true);
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! is_numeric($raw[$field] ?? null) || ! is_finite((float) $raw[$field])) {
                    return array_replace($sample, ['message' => 'Stored candles contain invalid data.']);
                }
            }
            $close = (float) $raw['close'];
            $timestamp = (int) $row->microtimestamp;
            if ($close <= 0 || $raw['open'] <= 0 || $raw['low'] <= 0 || $raw['volume'] < 0
                || $raw['high'] < max($close, $raw['open']) || $raw['low'] > min($close, $raw['open'])
                || $timestamp < 0 || $timestamp % 1000 !== 0 || ($inverse && ! is_finite(1 / $close))) {
                return array_replace($sample, ['message' => 'Stored candles contain invalid prices or timestamps.']);
            }
            if ($timestamps !== [] && $timestamp <= $timestamps[array_key_last($timestamps)]) {
                return array_replace($sample, ['message' => 'Stored candles contain duplicate or unordered timestamps.']);
            }
            $timestamps[] = $timestamp;
            $closes[] = $inverse ? 1 / $close : $close;
            $zeroVolume += (int) ((float) $raw['volume'] === 0.0);
            if ($includeSeries) {
                $series[] = ['time' => intdiv($timestamp, 1000), 'open' => (float) $raw['open'],
                    'high' => (float) $raw['high'], 'low' => (float) $raw['low'], 'close' => $close,
                    'volume' => (float) $raw['volume']];
            }
        }
        $count = count($closes);
        if ($count === 0) {
            return $sample;
        }
        $last = $timestamps[$count - 1];
        $continuous = true;
        for ($i = 1; $i < $count; $i++) {
            if ($step !== $timestamps[$i] - $timestamps[$i - 1]) {
                $continuous = false;
                break;
            }
        }
        $stale = $last + $step < $nowMs - max(3600000, $step * 2);
        $sample = array_replace($sample, ['last_close' => $stale ? null : $close, 'candles' => $count,
            'from' => gmdate('Y-m-d H:i', (int) ($timestamps[0] / 1000)),
            'through' => gmdate('Y-m-d H:i', (int) (($last + $step) / 1000)),
            'last_closed_at' => gmdate('Y-m-d\TH:i:s\Z', (int) (($last + $step) / 1000)),
            'age_seconds' => max(0, (int) (($nowMs - $last - $step) / 1000)),
            'stale' => $stale, 'continuous' => $continuous]);
        if ($includeSeries) {
            $sample['series'] = $series;
        }
        if ($stale) {
            return array_replace($sample, ['message' => 'Stored price history is stale.']);
        }
        if (! $continuous) {
            return array_replace($sample, ['message' => 'Stored price history has gaps.']);
        }
        if ($count < $sample['required_candles'] || $step > $hours * 3600000) {
            return $sample;
        }
        $peak = $closes[0];
        $drawdown = $largestMove = 0.0;
        foreach ($closes as $i => $price) {
            $peak = max($peak, $price);
            $drawdown = max($drawdown, 1 - $price / $peak);
            if ($i >= $window) {
                $largestMove = max($largestMove, abs($price / $closes[$i - $window] - 1));
            }
        }

        return array_replace($sample, ['known' => true, 'message' => 'Recent, continuous stored candle history.',
            'drawdown' => $drawdown, 'largest_move' => $largestMove, 'zero_volume_fraction' => $zeroVolume / $count]);
    }
}
