<?php

namespace App\Domain\MarketSuggestions;

use App\Domain\MarketData\CandleTimeframe;
use App\Models\Ticker;

use function Trademinator\Time\periods_to_seconds;

final class CandleEvidence
{
    public function inspect(string $exchange, string $symbol, ?string $feedPeriod, string $horizon, bool $inverse = false): array
    {
        $unknown = ['known' => false, 'message' => 'Insufficient stored history for your holding horizon.', 'last_close' => null];
        $query = Ticker::query()->where('exchange', $exchange)->where('symbol', $symbol);
        $period = (clone $query)->where('period', '1d')->exists() ? '1d' : $feedPeriod;
        // Fixed intervals only, so spacing and holding-window comparisons are exact.
        if (! in_array($period, CandleTimeframe::SUPPORTED, true) || str_contains((string) $period, 'M') || $period === '1y') {
            return $unknown;
        }
        $step = periods_to_seconds($period) * 1000;
        $nowMs = now()->getTimestamp() * 1000;
        $rows = $query->where('period', $period)->where('microtimestamp', '<=', $nowMs - $step)
            ->orderByDesc('microtimestamp')->limit(max(30, min(2000, (int) config('market_suggestions.candle_limit', 720))))
            ->get(['microtimestamp', 'payload'])->reverse();
        $closes = [];
        $timestamps = [];
        $zeroVolume = 0;
        foreach ($rows as $row) {
            $raw = json_decode($row->payload, true);
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (! is_numeric($raw[$field] ?? null) || ! is_finite((float) $raw[$field])) {
                    return array_replace($unknown, ['message' => 'Stored candles contain invalid data.']);
                }
            }
            $close = (float) $raw['close'];
            if ($close <= 0 || $raw['open'] <= 0 || $raw['low'] <= 0 || $raw['volume'] < 0
                || $raw['high'] < max($close, $raw['open']) || $raw['low'] > min($close, $raw['open'])) {
                return array_replace($unknown, ['message' => 'Stored candles contain invalid prices.']);
            }
            $timestamps[] = (int) $row->microtimestamp;
            $closes[] = $inverse ? 1 / $close : $close;
            $zeroVolume += (int) ((float) $raw['volume'] === 0.0);
            $unknown['last_close'] = $close;
        }
        $count = count($closes);
        if ($count === 0) {
            return $unknown;
        }
        $last = $timestamps[$count - 1];
        if ($last + $step < $nowMs - max(3600000, $step * 2)) {
            return array_replace($unknown, ['last_close' => null, 'message' => 'Stored price history is stale.']);
        }
        if ($count < 30) {
            return $unknown;
        }
        for ($i = 1; $i < $count; $i++) {
            if ($step !== $timestamps[$i] - $timestamps[$i - 1]) {
                return array_replace($unknown, ['message' => 'Stored price history has gaps.']);
            }
        }
        $hours = match ($horizon) {
            'hours' => 6, 'days' => 72, 'weeks' => 336, default => 168
        };
        $window = max(1, (int) ceil($hours * 3600000 / $step));
        if ($step > $hours * 3600000 || $count < $window * 3 + 1) {
            return $unknown;
        }
        $peak = $closes[0];
        $drawdown = $largestMove = 0.0;
        foreach ($closes as $i => $close) {
            $peak = max($peak, $close);
            $drawdown = max($drawdown, 1 - $close / $peak);
            if ($i >= $window) {
                $largestMove = max($largestMove, abs($close / $closes[$i - $window] - 1));
            }
        }

        return ['known' => true, 'message' => 'Recent, continuous stored candle history.',
            'last_close' => $unknown['last_close'], 'period' => $period, 'candles' => $count,
            'from' => gmdate('Y-m-d H:i', (int) ($timestamps[0] / 1000)),
            'through' => gmdate('Y-m-d H:i', (int) (($last + $step) / 1000)),
            'drawdown' => $drawdown, 'largest_move' => $largestMove, 'zero_volume_fraction' => $zeroVolume / $count];
    }
}
