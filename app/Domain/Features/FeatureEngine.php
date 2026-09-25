<?php

namespace App\Domain\Features;

use App\Domain\MarketData\CandleTimeframe;
use InvalidArgumentException;

/** Causal, versioned features. Raw decimal OHLCV is never overwritten. */
final class FeatureEngine
{
    public const VERSION = 'm2-v1';

    public const KEYS = [
        'trend.ema_3_12', 'trend.direction', 'return.4', 'return.12',
        'momentum.rsi_3', 'momentum.rsi_14', 'momentum.stoch_rsi_14', 'momentum.cci_20',
        'volatility.atrp_3', 'volatility.atrp_14', 'volume.activity_20',
        'candle.body', 'candle.upper_wick', 'candle.lower_wick', 'candle.direction',
        'return.24h', 'return.7d', 'return.30d',
    ];

    public static function bounded(?float $value, float $scale = 1): ?float
    {
        return $value === null || ! is_finite($value) ? null : 0.5 + 0.5 * tanh($value / $scale);
    }

    public static function ratio(mixed $a, mixed $b): ?float
    {
        if (! is_numeric($a) || ! is_numeric($b) || ! is_finite((float) $a) || ! is_finite((float) $b) || $a < 0 || $b <= 0) {
            return null;
        }
        $ratio = (float) $a / (float) $b;

        return is_finite($ratio) ? $ratio : null;
    }

    /** @return \Generator<array> Input must be chronological completed associative candles. */
    public function rows(iterable $candles, string $period, int $cutoffMs): \Generator
    {
        $timeframe = new CandleTimeframe;
        $window = $ema = $gain = $loss = $atr = $rsiHistory = $lag = [];
        $previous = $previousTime = null;
        $count = 0;
        $historyStart = null;
        foreach ($candles as $raw) {
            $timestamp = $raw['microtimestamp'] ?? null;
            if (! is_numeric($timestamp) || (float) $timestamp != (int) $timestamp || $timestamp < 0) {
                throw new InvalidArgumentException('Candle timestamp must be nonnegative integer milliseconds.');
            }
            $timestamp = (int) $timestamp;
            if ($previousTime !== null && $timestamp <= $previousTime) {
                throw new InvalidArgumentException('Candles must have unique ascending timestamps.');
            }
            $closeAt = $timeframe->next($timestamp, $period);
            if ($closeAt > $cutoffMs) {
                break;
            }
            foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                if (! isset($raw[$key]) || ! is_numeric($raw[$key]) || ! is_finite((float) $raw[$key]) || $raw[$key] < 0) {
                    throw new InvalidArgumentException('Invalid OHLCV field: '.$key);
                }
            }
            $o = (float) $raw['open'];
            $h = (float) $raw['high'];
            $l = (float) $raw['low'];
            $c = (float) $raw['close'];
            $v = (float) $raw['volume'];
            if (min($o, $h, $l, $c) <= 0 || $h < max($o, $c, $l) || $l > min($o, $c)) {
                throw new InvalidArgumentException('Invalid candle price bounds.');
            }
            // Missing intervals break indicator continuity; never synthesize candles.
            if ($previousTime !== null && $timestamp !== $timeframe->next($previousTime, $period)) {
                $window = $ema = $gain = $loss = $atr = $rsiHistory = $lag = [];
                $previous = null;
                $count = 0;
                $historyStart = null;
            }
            $historyStart ??= $timestamp;
            $count++;
            $window[] = ['close' => $c, 'tp' => ($h + $l + $c) / 3, 'volume' => $v];
            if (count($window) > 20) {
                array_shift($window);
            }
            $indicators = [];
            $features = array_fill_keys(self::KEYS, null);
            foreach ([3, 12] as $p) {
                if ($count === $p) {
                    $ema[$p] = array_sum(array_column(array_slice($window, -$p), 'close')) / $p;
                } elseif ($count > $p) {
                    $ema[$p] += 2 / ($p + 1) * ($c - $ema[$p]);
                }
                $indicators["ema($p,close)"] = $count >= $p ? $ema[$p] : null;
            }
            if ($count >= 12) {
                $features['trend.ema_3_12'] = self::bounded(($ema[3] - $ema[12]) / $c, 0.05);
                $features['trend.direction'] = $ema[3] <=> $ema[12];
            }
            foreach ([4, 12] as $p) {
                $old = $window[count($window) - $p - 1]['close'] ?? null;
                $indicators["return($p,close)"] = $old === null ? null : $c / $old - 1;
                $features["return.$p"] = self::bounded($indicators["return($p,close)"], 0.1);
            }
            foreach ([3, 14] as $p) {
                $delta = $previous === null ? null : $c - $previous;
                if ($delta !== null) {
                    if ($count <= $p + 1) {
                        $gain[$p] = ($gain[$p] ?? 0) + max(0, $delta) / $p;
                        $loss[$p] = ($loss[$p] ?? 0) + max(0, -$delta) / $p;
                    } else {
                        $gain[$p] = ($gain[$p] * ($p - 1) + max(0, $delta)) / $p;
                        $loss[$p] = ($loss[$p] * ($p - 1) + max(0, -$delta)) / $p;
                    }
                }
                $rsi = $count <= $p ? null : ($gain[$p] + $loss[$p] == 0 ? 50.0 : 100 * $gain[$p] / ($gain[$p] + $loss[$p]));
                $indicators["rsi($p)"] = $rsi;
                $features["momentum.rsi_$p"] = $rsi === null ? null : $rsi / 100;
                $tr = $previous === null ? $h - $l : max($h - $l, abs($h - $previous), abs($l - $previous));
                $atr[$p] = $count <= $p ? ($atr[$p] ?? 0) + $tr / $p : ($atr[$p] * ($p - 1) + $tr) / $p;
                $indicators["atrp($p)"] = $count < $p ? null : 100 * $atr[$p] / $c;
                $features["volatility.atrp_$p"] = $count < $p ? null : min(1.0, $atr[$p] / $c / 0.1);
            }
            if ($features['momentum.rsi_14'] !== null) {
                $rsiHistory[] = $features['momentum.rsi_14'];
                if (count($rsiHistory) > 14) {
                    array_shift($rsiHistory);
                }
                if (count($rsiHistory) === 14) {
                    $span = max($rsiHistory) - min($rsiHistory);
                    $features['momentum.stoch_rsi_14'] = $span == 0 ? 0.5 : (end($rsiHistory) - min($rsiHistory)) / $span;
                }
            }
            $indicators['stoch_rsi(14,14)'] = $features['momentum.stoch_rsi_14'];
            $indicators['cci(20)'] = null;
            if ($count >= 20) {
                $tp = array_column($window, 'tp');
                $mean = array_sum($tp) / 20;
                $mad = array_sum(array_map(fn ($x) => abs($x - $mean), $tp)) / 20;
                $indicators['cci(20)'] = $mad == 0 ? 0.0 : (end($tp) - $mean) / (0.015 * $mad);
                $features['momentum.cci_20'] = self::bounded($indicators['cci(20)'], 200);
                $volumeMean = array_sum(array_column($window, 'volume')) / 20;
                $features['volume.activity_20'] = $volumeMean == 0 ? 0.5 : self::bounded($v / $volumeMean - 1, 2);
            }
            $range = $h - $l;
            $features['candle.body'] = $range == 0 ? 0.0 : abs($c - $o) / $range;
            $features['candle.upper_wick'] = $range == 0 ? 0.0 : ($h - max($o, $c)) / $range;
            $features['candle.lower_wick'] = $range == 0 ? 0.0 : (min($o, $c) - $l) / $range;
            $features['candle.direction'] = $c <=> $o;
            // Exact elapsed-time anchors, not N bars disguised as a day. 30d is not a calendar month.
            $lag[$timestamp] = $c;
            foreach (['24h' => 86400000, '7d' => 604800000, '30d' => 2592000000] as $name => $ms) {
                $old = $lag[$timestamp - $ms] ?? null;
                $indicators["return($name)"] = $old === null ? null : $c / $old - 1;
                $features['return.'.$name] = self::bounded($indicators["return($name)"], 0.1);
            }
            while (array_key_first($lag) < $timestamp - 2592000000) {
                unset($lag[array_key_first($lag)]);
            }
            $previous = $c;
            $previousTime = $timestamp;
            yield ['close' => $c, 'microtimestamp' => $timestamp, 'available_at_ms' => $closeAt, 'history_start_ms' => $historyStart,
                'indicators' => $indicators, 'features' => $features,
                'technical_ready' => $count >= 28];
        }
    }
}
