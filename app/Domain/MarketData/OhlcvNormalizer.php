<?php

namespace App\Domain\MarketData;

use InvalidArgumentException;

final class OhlcvNormalizer
{
    /**
     * Convert raw CCXT OHLCV rows or already-associative candles into the
     * canonical Trademinator candle shape.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function normalize(array $candles, bool $reindex = false): array
    {
        $normalized = [];

        foreach ($candles as $candle) {
            if (! is_array($candle)) {
                throw new InvalidArgumentException('Each OHLCV candle must be an array.');
            }

            $timestamp = $candle['microtimestamp'] ?? $candle[0] ?? null;
            $open = $candle['open'] ?? $candle[1] ?? null;
            $high = $candle['high'] ?? $candle[2] ?? null;
            $low = $candle['low'] ?? $candle[3] ?? null;
            $close = $candle['close'] ?? $candle[4] ?? null;
            $volume = $candle['volume'] ?? $candle[5] ?? 0;

            if (! is_numeric($timestamp)) {
                throw new InvalidArgumentException('OHLCV timestamp must be numeric.');
            }

            foreach (['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close] as $field => $value) {
                if (! is_numeric($value)) {
                    throw new InvalidArgumentException("OHLCV {$field} must be numeric.");
                }
            }

            if ($volume !== null && ! is_numeric($volume)) {
                throw new InvalidArgumentException('OHLCV volume must be numeric or null.');
            }

            $microtimestamp = (int) $timestamp;
            $row = [];

            // Preserve previously-computed associative indicator keys, while
            // deliberately dropping CCXT's numeric 0..5 indexes.
            foreach ($candle as $key => $value) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    $row[$key] = $value;
                }
            }

            $row['human_date'] = gmdate('Y-m-d H:i:s', intdiv($microtimestamp, 1000));
            $row['microtimestamp'] = $microtimestamp;
            $row['open'] = $this->decimal($open);
            $row['high'] = $this->decimal($high);
            $row['low'] = $this->decimal($low);
            $row['close'] = $this->decimal($close);
            $row['volume'] = $this->decimal($volume ?? 0);

            if ($reindex) {
                $normalized[$microtimestamp] = $row;
            } else {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    private function decimal(int|float|string $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }
}
