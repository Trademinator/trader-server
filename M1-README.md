# M1 — Trustworthy market data

The exchange through CCXT remains the source for OHLCV and execution. M1 extends the existing `SelectCandlePeriod` command. It does not add CoinGecko to candle ingestion.

## What changed

- CCXT OHLCV is normalized into the existing associative candle shape. Numeric strings retain their decimal precision instead of being rounded to eight places. Invalid timestamps, OHLC and volume are rejected.
- Fetching includes the requested end boundary, advances through empty windows, handles the variable length of calendar months and years, sorts/deduplicates results, and upserts recent candles by `(exchange, symbol, period, microtimestamp)`. CCXT rate limiting is always enabled.
- `trademinator:select-candle-period` examines exchange-supported timeframes from shortest to longest. It scores true-flat candles, longest flat run, zero-volume ratio, distinct closes, median high/low range in ticks and relative to price. It rejects inadequate samples, sparse intervals and stale or unfinished candles. It stops at the first acceptable timeframe and records the successful decision in `candle_period_selections`. It returns a failure if none qualifies.
- `trademinator:sync-ohlcv` supports bounded history, incremental refresh of the newest stored candle, gap inspection, one repair attempt per gap (up to 100 per run), and queued jobs with retries and backoff. Missing intervals remain missing if the exchange supplies no candle; no volume or price is invented.

## Installation and use

Run `composer install` and `php artisan migrate`. Start a Laravel queue worker if using `--queue` and set `QUEUE_CONNECTION` to a persistent queue driver; `sync` runs inline.

```bash
php artisan trademinator:sync-ohlcv coinbase BTC/USD 1m --from='7 days ago' --incremental --repair-gaps
php artisan trademinator:sync-ohlcv coinbase BTC/USD 1m --from='90 days ago' --queue
php artisan trademinator:select-candle-period coinbase BTC/USD --tick-size=0.01 --threshold=0.7 --coverage=0.8 --minimum=50 --sample=250 --from='7 days ago'
```

Supply `--tick-size` from the market's CCXT tick-size metadata, accounting for that exchange's precision mode. A closed candle has reached its timeframe end at the requested cutoff and at the current time. The selector samples at most the latest 250 closed candles per timeframe; increase `--sample` for a longer window. `--minimum` cannot exceed `--sample`. Coverage is the fraction of expected candles between the first and last sampled observations; gaps may reflect no-trade periods on illiquid markets. A high price score does not override poor coverage.

The `candle_period_selections` table stores the selected exchange/symbol/timeframe, sample bounds, thresholds and quality metrics for audit. It does not place trades. All timestamps in the candle and decision payload are milliseconds since Unix epoch.

## Verification

```bash
composer test
```

The test cases cover precision, calendar boundaries, sparse samples, Coinbase empty windows, recent updates, and selection excluding the active candle. Validate against liquid, moderate, illiquid and borderline real exchange pairs before relying on the default thresholds. PHP and Composer were unavailable in the authoring environment, so the suite could not run there.
