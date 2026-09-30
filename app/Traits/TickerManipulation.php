<?php

namespace App\Traits;

use Generator;
use InvalidArgumentException;
use LogicException;

trait TickerManipulation
{
    use Bc;

    /**
     * Name CCXT OHLCV columns. Reindex by Unix seconds when requested; retain
     * the original millisecond timestamp in every candle. No price rounding.
     *
     * @param  array<int|string, array<int|string, mixed>>  $tickers
     * @return array<int|string, array<string, mixed>>
     */
    public function normalize_ticker(array &$tickers, bool $reindex = false, string $indexUnit = 'seconds'): array
    {
        if (! in_array($indexUnit, ['seconds', 'milliseconds'], true)) {
            throw new InvalidArgumentException('Ticker index unit must be seconds or milliseconds.');
        }

        $normalized = [];
        foreach ($tickers as $originalKey => $ticker) {
            if (! is_array($ticker)) {
                throw new InvalidArgumentException('Each OHLCV candle must be an array.');
            }

            $timestamp = $ticker['microtimestamp'] ?? $ticker[0] ?? null;
            if ((! is_int($timestamp) && ! is_string($timestamp)) || ! ctype_digit((string) $timestamp)
                || filter_var($timestamp, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('OHLCV timestamp must be a non-negative integer in milliseconds.');
            }
            $timestamp = (int) $timestamp;
            $row = [];
            foreach ($ticker as $key => $value) {
                if (! is_int($key) && ! ctype_digit((string) $key)) {
                    $row[$key] = $value;
                }
            }

            $row['human_date'] = gmdate('YmdHis', intdiv($timestamp, 1000));
            $row['microtimestamp'] = $timestamp;
            foreach ([1 => 'open', 2 => 'high', 3 => 'low', 4 => 'close', 5 => 'volume'] as $offset => $name) {
                $value = $ticker[$name] ?? $ticker[$offset] ?? ($name === 'volume' ? '0' : null);
                if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    throw new InvalidArgumentException('OHLCV '.$name.' must be numeric.');
                }
                $row[$name] = $this->bcconv($value);
            }

            $scale = $this->bcdec($row['open'], $row['high'], $row['low'], $row['close'], $row['volume']);
            if (bccomp($row['high'], $row['low'], $scale) < 0
                || bccomp($row['high'], $row['open'], $scale) < 0
                || bccomp($row['high'], $row['close'], $scale) < 0
                || bccomp($row['low'], $row['open'], $scale) > 0
                || bccomp($row['low'], $row['close'], $scale) > 0
                || bccomp($row['low'], '0', $scale) < 0
                || bccomp($row['volume'], '0', $scale) < 0) {
                throw new InvalidArgumentException('Invalid OHLCV values.');
            }

            $key = $reindex
                ? ($indexUnit === 'milliseconds' ? $timestamp : intdiv($timestamp, 1000))
                : $originalKey;
            if (array_key_exists($key, $normalized)) {
                throw new InvalidArgumentException('Duplicate ticker index; use milliseconds for distinct sub-second candles.');
            }
            // Copy values, not references to the foreach variable.
            $normalized[$key] = $row;
        }

        // Commit only after every row validates, so an error leaves input intact.
        $tickers = $normalized;

        return $tickers;
    }

    /**
     * Keep the latest period * multiplier candles, preserving timestamp keys.
     * A calculated boundary row carries recursive indicators, never a guessed
     * re-seed from two or three periods of raw prices. Initial warm-up can keep
     * up to (multiplier + 2) * period rows before it is safe to trim.
     *
     * @param  array<int|string, array<string, mixed>>  $tickers
     * @return array<int|string, array<string, mixed>>
     */
    public function ticker_slice(array $tickers, int $period, int $multiplier = 3): array
    {
        $this->validate_ticker_window($period, $multiplier);
        if ($tickers === []) {
            return [];
        }

        $length = $period * $multiplier;
        $offset = max(0, count($tickers) - $length);
        $firstPosition = (int) (reset($tickers)['__ticker_position'] ?? 1);
        if ($offset > 0 && $firstPosition + $offset < $period * 2) {
            $offset = 0;
        }
        $slice = array_slice($tickers, $offset, null, true);
        $position = $firstPosition + $offset;
        $first = true;
        foreach ($slice as &$ticker) {
            unset($ticker['__ticker_seed']);
            $ticker['__ticker_position'] = $position++;
            $ticker['__ticker_cached'] = true;
            if ($first && $ticker['__ticker_position'] > 1) {
                $ticker['__ticker_seed'] = true;
            }
            $first = false;
        }
        unset($ticker);

        return $slice;
    }

    /**
     * Run ordinary array-based trait methods over overlapping slices. The
     * callback mutates its array argument by reference. Only new rows are
     * emitted; overlap and boundary bookkeeping never reach callers or DB.
     *
     * @param  iterable<int|string, array<string, mixed>>  $tickers  Chronological, unique keys.
     * @param  callable(array&): mixed  $calculate
     * @param  null|callable(array, array): bool  $continuous  Optional gap/segment check.
     * @return Generator<int|string, array<string, mixed>>
     */
    public function ticker_slide(iterable $tickers, callable $calculate, int $period, int $multiplier = 3, int $batchSize = 500, ?callable $continuous = null): Generator
    {
        $this->validate_ticker_window($period, $multiplier);
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Ticker batch size must be positive.');
        }
        $pending = $carry = [];
        $position = 0;
        $previous = null;
        $previousKey = null;
        $flush = function () use (&$pending, &$carry, $calculate, $period, $multiplier): Generator {
            $newKeys = array_keys($pending);
            $slice = $carry + $pending;
            $calculate($slice);
            if (array_keys($slice) !== array_keys($carry + $pending)) {
                throw new LogicException('Ticker calculation must preserve the slice keys and order.');
            }
            $carry = $this->ticker_slice($slice, $period, $multiplier);
            $pending = [];
            foreach ($newKeys as $key) {
                $row = $slice[$key];
                unset($row['__ticker_position'], $row['__ticker_seed'], $row['__ticker_cached']);
                yield $key => $row;
            }
        };

        foreach ($tickers as $key => $ticker) {
            if (! is_array($ticker)) {
                throw new InvalidArgumentException('Each ticker must be an array.');
            }
            if (array_key_exists($key, $pending) || array_key_exists($key, $carry)
                || (is_int($key) && is_int($previousKey) && $key <= $previousKey)) {
                throw new InvalidArgumentException('Ticker slices require unique, ascending keys.');
            }
            if ($previous !== null && $continuous !== null && ! $continuous($previous, $ticker)) {
                if ($pending !== []) {
                    yield from $flush();
                }
                $carry = [];
                $position = 0;
            }
            unset($ticker['__ticker_seed'], $ticker['__ticker_cached']);
            $ticker['__ticker_position'] = ++$position;
            $pending[$key] = $ticker;
            $previous = $ticker;
            $previousKey = $key;
            if (count($pending) === $batchSize) {
                yield from $flush();
            }
        }
        if ($pending !== []) {
            yield from $flush();
        }
    }

    public function delete_key(array &$tickers, string ...$keys): void
    {
        foreach ($tickers as &$ticker) {
            foreach ($keys as $key) {
                unset($ticker[$key]);
            }
        }
        unset($ticker);
    }

    public function clone_key(array &$tickers, string $oldkey, string $newkey): array
    {
        foreach ($tickers as &$ticker) {
            $ticker[$newkey] = $ticker[$oldkey];
        }
        unset($ticker);

        return $tickers;
    }

    /** Only explicitly retained overlap is trusted; ordinary arrays are recalculated. */
    private function ticker_cached(array $ticker, string $key): bool
    {
        return ($ticker['__ticker_cached'] ?? false) && array_key_exists($key, $ticker);
    }

    /** @return array<string, mixed>|null */
    private function ticker_seed(array $tickers, array $keys, int $warmup): ?array
    {
        $first = reset($tickers);
        if ($first === false || ! ($first['__ticker_seed'] ?? false)) {
            return null;
        }
        foreach ($keys as $key) {
            if (! isset($first[$key]) || ! is_numeric($first[$key]) || $first['__ticker_position'] < $warmup) {
                throw new LogicException('Missing mature '.$key.' slice seed. Use ticker_slide() on complete history before taking its last slice.');
            }
        }

        return $first;
    }

    /** Fail instead of emitting a partial-window value after history was trimmed. */
    private function ticker_require_history(array $tickers, int $lookback): void
    {
        $first = reset($tickers);
        if ($first === false || ! ($first['__ticker_seed'] ?? false)) {
            return;
        }
        $available = 0;
        foreach ($tickers as $ticker) {
            if (! ($ticker['__ticker_cached'] ?? false)) {
                if ($available < $lookback) {
                    throw new LogicException('Ticker overlap is shorter than the indicator lookback; increase the slice period or multiplier.');
                }

                return;
            }
            $available++;
        }
    }

    private function validate_ticker_window(int $period, int $multiplier): void
    {
        if ($period < 1 || $multiplier < 1 || $multiplier > PHP_INT_MAX - 2
            || $period > intdiv(PHP_INT_MAX, $multiplier + 2)) {
            throw new InvalidArgumentException('Ticker period and multiplier must be positive and fit an integer window size.');
        }
    }
}
