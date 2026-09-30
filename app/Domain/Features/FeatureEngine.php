<?php

namespace App\Domain\Features;

use App\Domain\MarketData\CandleTimeframe;
use App\Traits\Technical;
use InvalidArgumentException;

/** Causal, versioned features. Raw decimal OHLCV is never overwritten. */
final class FeatureEngine
{
    use Technical;

    public const VERSION = 'm2-v3';

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

    /** @return \Generator<array> Chronological, completed candles only. */
    public function rows(iterable $candles, string $period, int $cutoffMs, int $batchSize = 500,
        ?array $checkpoint = null, ?callable $checkpointCallback = null): \Generator
    {
        $calculated = $this->calculatedCandles($candles, $period, $cutoffMs, $batchSize, $checkpoint, $checkpointCallback);
        $elapsedCloses = $checkpoint['elapsed_closes'] ?? [];
        $historyStart = $checkpoint['history_start_ms'] ?? null;
        $scale = EXCHANGE_ROUND_DECIMALS * 2;
        foreach ($calculated as $candle) {
            $timestamp = $candle['microtimestamp'];
            $count = $candle['__feature_count'];
            $close = $candle['close'];
            if ($historyStart !== $candle['history_start_ms']) {
                $elapsedCloses = [];
                $historyStart = $candle['history_start_ms'];
            }
            $indicators = [];
            $features = array_fill_keys(self::KEYS, null);
            foreach ([3, 12] as $p) {
                $indicators["ema($p,close)"] = $count >= $p ? $candle["ema($p,close)"] : null;
            }
            if ($count >= 12) {
                $trend = $this->technical_normalized_difference_value($candle['ema(3,close)'], $candle['ema(12,close)'], $close);
                $features['trend.ema_3_12'] = self::bounded($trend === null ? null : (float) $trend, 0.05);
                $features['trend.direction'] = $candle['compare(ema(3,close),ema(12,close))'];
            }
            foreach ([4, 12] as $p) {
                $change = $count > $p ? bcdiv($candle["roc($p,close)"], '100', $scale) : null;
                $indicators["return($p,close)"] = $change;
                $features["return.$p"] = self::bounded($change === null ? null : (float) $change, 0.1);
            }
            foreach ([3, 14] as $p) {
                $rsi = $count > $p ? $candle["rsi($p)"] : null;
                $indicators["rsi($p)"] = $rsi;
                $features["momentum.rsi_$p"] = $rsi === null ? null : (float) bcdiv($rsi, '100', $scale);
                $atrp = $count >= $p ? $candle["atrp($p)"] : null;
                $indicators["atrp($p)"] = $atrp;
                if ($atrp !== null) {
                    $scaled = bcdiv($atrp, '10', $scale);
                    $features["volatility.atrp_$p"] = bccomp($scaled, '1', $scale) > 0 ? 1.0 : (float) $scaled;
                }
            }
            $stochastic = $count >= 28 ? $candle['%k(14)'] : null;
            $indicators['stoch_rsi(14,14)'] = $stochastic;
            $features['momentum.stoch_rsi_14'] = $stochastic === null ? null : (float) bcdiv($stochastic, '100', $scale);
            $cci = $count >= 20 ? $candle['cci(20)'] : null;
            $indicators['cci(20)'] = $cci;
            $features['momentum.cci_20'] = $cci === null ? null : self::bounded((float) $cci, 200);
            $features['volume.activity_20'] = $count >= 20 ? self::bounded((float) $candle['volume_activity(20)'], 2) : null;
            foreach (['body', 'upper_wick', 'lower_wick'] as $part) {
                $features['candle.'.$part] = (float) $candle['candle.'.$part];
            }
            $features['candle.direction'] = $candle['candle.direction'];

            // Elapsed-time return anchors are independent of the indicator slice.
            $elapsedCloses[$timestamp] = $close;
            foreach (['24h' => 86400000, '7d' => 604800000, '30d' => 2592000000] as $name => $duration) {
                $past = $elapsedCloses[$timestamp - $duration] ?? null;
                $change = $past === null ? null : $this->technical_relative_change_value($close, $past);
                $indicators['return('.$name.')'] = $change;
                $features['return.'.$name] = self::bounded($change === null ? null : (float) $change, 0.1);
            }
            while ($elapsedCloses !== [] && array_key_first($elapsedCloses) < $timestamp - 2592000000) {
                unset($elapsedCloses[array_key_first($elapsedCloses)]);
            }
            yield [
                'close' => (float) $close,
                'microtimestamp' => $timestamp,
                'available_at_ms' => $candle['available_at_ms'],
                'history_start_ms' => $historyStart,
                'indicators' => $indicators,
                'features' => $features,
                'technical_ready' => $count >= 28,
            ];
        }
    }

    /** Continue recursive indicator state from a trusted M4.3 checkpoint. */
    private function calculatedCandles(iterable $candles, string $period, int $cutoffMs, int $batchSize,
        ?array $checkpoint, ?callable $checkpointCallback): \Generator
    {
        $carry = $checkpoint['carry'] ?? [];
        $seed = [
            'previous' => $checkpoint['through_ms'] ?? null,
            'history_start_ms' => $checkpoint['history_start_ms'] ?? null,
            'count' => (int) ($checkpoint['feature_count'] ?? 0),
        ];
        $pending = [];
        $flush = function () use (&$pending, &$carry, $checkpointCallback): \Generator {
            if ($pending === []) {
                return;
            }
            $newKeys = array_keys($pending);
            $slice = $carry + $pending;
            $this->calculateSlice($slice);
            $carry = $this->ticker_slice($slice, 20, 3);
            $last = end($carry);
            if (is_array($last)) {
                $checkpointCallback?->__invoke([
                    'carry' => array_values($carry),
                    'through_ms' => (int) $last['microtimestamp'],
                    'history_start_ms' => (int) $last['history_start_ms'],
                    'feature_count' => (int) ($last['__feature_count'] ?? 0),
                ]);
            }
            $pending = [];
            foreach ($newKeys as $key) {
                $row = $slice[$key];
                unset($row['__ticker_position'], $row['__ticker_seed'], $row['__ticker_cached']);
                yield $key => $row;
            }
        };

        foreach ($this->closedCandles($candles, $period, $cutoffMs, $seed) as $key => $row) {
            $pendingLast = end($pending);
            if (is_array($pendingLast) && ($pendingLast['history_start_ms'] ?? null) !== ($row['history_start_ms'] ?? null)) {
                yield from $flush();
                $carry = [];
            } elseif ($pending === []) {
                $carryLast = end($carry);
                if (is_array($carryLast) && ($carryLast['history_start_ms'] ?? null) !== ($row['history_start_ms'] ?? null)) {
                    $carry = [];
                }
            }
            $pending[$key] = $row;
            if (count($pending) >= $batchSize) {
                yield from $flush();
            }
        }
        if ($pending !== []) {
            yield from $flush();
        }
    }

    /** The ordinary public trait APIs are the sole indicator implementations. */
    private function calculateSlice(array &$slice): void
    {
        $this->ema($slice, 3, 'close');
        $this->ema($slice, 12, 'close');
        $this->compare($slice, 'ema(3,close)', 'ema(12,close)');
        $this->roc($slice, 4, 'close');
        $this->roc($slice, 12, 'close');
        $this->rsi($slice, 3);
        $this->rsi($slice, 14);
        $this->atrp($slice, 3);
        $this->atrp($slice, 14);
        $this->sto_rsi($slice, 14, 3, 3);
        $this->cci($slice, 20);
        $this->volume_activity($slice, 20);
        $this->candle_geometry($slice);
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function closedCandles(iterable $candles, string $period, int $cutoffMs, array $seed = []): \Generator
    {
        $timeframe = new CandleTimeframe;
        $previous = $seed['previous'] ?? null;
        $historyStart = $seed['history_start_ms'] ?? null;
        $count = (int) ($seed['count'] ?? 0);
        foreach ($candles as $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException('Each candle must be an array.');
            }
            $timestamp = $raw['microtimestamp'] ?? null;
            if ((! is_int($timestamp) && ! is_string($timestamp)) || ! ctype_digit((string) $timestamp)
                || filter_var($timestamp, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('Candle timestamp must be nonnegative integer milliseconds.');
            }
            $timestamp = (int) $timestamp;
            if ($previous !== null && $timestamp <= $previous) {
                throw new InvalidArgumentException('Candles must have unique ascending timestamps.');
            }
            $closeAt = $timeframe->next($timestamp, $period);
            if ($closeAt > $cutoffMs) {
                break;
            }
            // Ignore incoming indicator/cache fields: rebuild solely from raw OHLCV.
            $row = ['microtimestamp' => $timestamp];
            foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
                if (! isset($raw[$key])) {
                    throw new InvalidArgumentException('Invalid OHLCV field: '.$key);
                }
                $row[$key] = $raw[$key];
            }
            $normalized = [$row];
            $this->normalize_ticker($normalized);
            $row = $normalized[0];
            $precision = $this->bcdec($row['low']);
            if (bccomp($row['low'], '0', $precision) <= 0 || ! is_finite((float) $row['close'])) {
                throw new InvalidArgumentException('Invalid candle price bounds.');
            }
            if ($previous !== null && $timestamp !== $timeframe->next($previous, $period)) {
                $count = 0;
                $historyStart = null;
            }
            $historyStart ??= $timestamp;
            $row['history_start_ms'] = $historyStart;
            $row['available_at_ms'] = $closeAt;
            $row['__feature_count'] = ++$count;
            $previous = $timestamp;
            yield $timestamp => $row;
        }
    }
}
