<?php

namespace App\Domain\Features;

use App\Domain\MarketData\CandleTimeframe;
use App\Traits\Technical;
use InvalidArgumentException;

/** Causal, versioned features. Raw decimal OHLCV is never overwritten. */
final class FeatureEngine
{
    use Technical;

    public const VERSION = 'm2-v2';

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
        $previousTime = null;
        $count = 0;
        $historyStart = null;
        $states = $this->freshTechnicalStates();
        $barCloses = [];
        $elapsedCloses = [];

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

            $o = (string) $raw['open'];
            $h = (string) $raw['high'];
            $l = (string) $raw['low'];
            $c = (string) $raw['close'];
            $v = (string) $raw['volume'];
            if ((float) min((float) $o, (float) $h, (float) $l, (float) $c) <= 0
                || (float) $h < max((float) $o, (float) $c, (float) $l)
                || (float) $l > min((float) $o, (float) $c)) {
                throw new InvalidArgumentException('Invalid candle price bounds.');
            }

            // Missing intervals break indicator continuity; never synthesize candles.
            if ($previousTime !== null && $timestamp !== $timeframe->next($previousTime, $period)) {
                $states = $this->freshTechnicalStates();
                $barCloses = [];
                $elapsedCloses = [];
                $count = 0;
                $historyStart = null;
            }

            $historyStart ??= $timestamp;
            $count++;
            $indicators = [];
            $features = array_fill_keys(self::KEYS, null);

            // All OHLCV-derived mathematics comes from App\Traits\Technical.
            $ema3 = $this->technical_ema_next($states['ema'][3], $c, 3);
            $ema12 = $this->technical_ema_next($states['ema'][12], $c, 12);
            $indicators['ema(3,close)'] = $count >= 3 ? $ema3 : null;
            $indicators['ema(12,close)'] = $count >= 12 ? $ema12 : null;
            if ($count >= 12) {
                $trend = $this->technical_normalized_difference_value($ema3, $ema12, $c);
                $features['trend.ema_3_12'] = self::bounded($trend === null ? null : (float) $trend, 0.05);
                $features['trend.direction'] = $this->technical_compare_value($ema3, $ema12);
            }

            foreach ([4, 12] as $p) {
                $old = $barCloses[count($barCloses) - $p] ?? null;
                $change = $old === null ? null : $this->technical_relative_change_value($c, $old);
                $indicators["return($p,close)"] = $change;
                $features["return.$p"] = self::bounded($change === null ? null : (float) $change, 0.1);
            }
            $barCloses[] = $c;
            if (count($barCloses) > 12) {
                array_shift($barCloses);
            }

            $rsi3 = $this->technical_rsi_next($states['rsi'][3], $c, 3);
            $rsi14 = $this->technical_rsi_next($states['rsi'][14], $c, 14);
            foreach ([3 => $rsi3, 14 => $rsi14] as $p => $rsi) {
                $indicators["rsi($p)"] = $rsi['mature'] ? $rsi['value'] : null;
                $features["momentum.rsi_$p"] = $rsi['mature']
                    ? (float) bcdiv($rsi['value'], '100', EXCHANGE_ROUND_DECIMALS * 2)
                    : null;
            }

            $atrp3 = $this->technical_atrp_next($states['atrp'][3], $h, $l, $c, 3);
            $atrp14 = $this->technical_atrp_next($states['atrp'][14], $h, $l, $c, 14);
            foreach ([3 => $atrp3, 14 => $atrp14] as $p => $atrp) {
                $indicators["atrp($p)"] = $atrp['mature'] ? $atrp['value'] : null;
                if ($atrp['mature']) {
                    $scaled = bcdiv($atrp['value'], '10', EXCHANGE_ROUND_DECIMALS * 2);
                    $features["volatility.atrp_$p"] = bccomp($scaled, '1', EXCHANGE_ROUND_DECIMALS * 2) > 0
                        ? 1.0
                        : (float) $scaled;
                }
            }

            $stochRsi = $this->technical_stoch_rsi_next(
                $states['stoch_rsi_14'],
                $rsi14['value'],
                $rsi14['mature'],
                14
            );
            $indicators['stoch_rsi(14,14)'] = $stochRsi['mature'] ? $stochRsi['value'] : null;
            $features['momentum.stoch_rsi_14'] = $stochRsi['mature']
                ? (float) bcdiv($stochRsi['value'], '100', EXCHANGE_ROUND_DECIMALS * 2)
                : null;

            $cci = $this->technical_cci_next($states['cci_20'], $h, $l, $c, 20);
            $indicators['cci(20)'] = $cci['mature'] ? $cci['value'] : null;
            $features['momentum.cci_20'] = $cci['mature'] ? self::bounded((float) $cci['value'], 200) : null;

            $volume = $this->technical_volume_activity_next($states['volume_20'], $v, 20);
            $features['volume.activity_20'] = $volume['mature'] ? self::bounded((float) $volume['value'], 2) : null;

            $geometry = $this->technical_candle_geometry($o, $h, $l, $c);
            $features['candle.body'] = (float) $geometry['body'];
            $features['candle.upper_wick'] = (float) $geometry['upper_wick'];
            $features['candle.lower_wick'] = (float) $geometry['lower_wick'];
            $features['candle.direction'] = $geometry['direction'];

            // Exact elapsed-time anchors, not N bars disguised as a day. 30d is not a calendar month.
            $elapsedCloses[$timestamp] = $c;
            foreach (['24h' => 86400000, '7d' => 604800000, '30d' => 2592000000] as $name => $ms) {
                $old = $elapsedCloses[$timestamp - $ms] ?? null;
                $change = $old === null ? null : $this->technical_relative_change_value($c, $old);
                $indicators["return($name)"] = $change;
                $features['return.'.$name] = self::bounded($change === null ? null : (float) $change, 0.1);
            }
            while ($elapsedCloses !== [] && array_key_first($elapsedCloses) < $timestamp - 2592000000) {
                unset($elapsedCloses[array_key_first($elapsedCloses)]);
            }

            $previousTime = $timestamp;
            yield [
                'close' => (float) $c,
                'microtimestamp' => $timestamp,
                'available_at_ms' => $closeAt,
                'history_start_ms' => $historyStart,
                'indicators' => $indicators,
                'features' => $features,
                'technical_ready' => $count >= 28,
            ];
        }
    }

    private function freshTechnicalStates(): array
    {
        return [
            'ema' => [3 => [], 12 => []],
            'rsi' => [3 => [], 14 => []],
            'atrp' => [3 => [], 14 => []],
            'stoch_rsi_14' => [],
            'cci_20' => [],
            'volume_20' => [],
        ];
    }
}
