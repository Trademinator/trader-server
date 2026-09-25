# M1 — Trustworthy market data

Complete command reference: [CLI.md](CLI.md).

The exchange through CCXT remains the source for OHLCV and execution. M1 extends the existing `SelectCandlePeriod` command. It does not add CoinGecko to candle ingestion.

## What changed

- CCXT OHLCV is normalized into the existing associative candle shape. Numeric strings retain their decimal precision instead of being rounded to eight places. Invalid timestamps, OHLC and volume are rejected.
- Fetching includes the requested end boundary, advances through empty windows, handles the variable length of calendar months and years, sorts/deduplicates results, and upserts recent candles by `(exchange, symbol, period, microtimestamp)`. CCXT rate limiting is always enabled.
- `trademinator:select-candle-period` examines exchange-supported timeframes from shortest to longest. It scores true-flat candles, longest flat run, zero-volume ratio, distinct closes, median high/low range in ticks and relative to price. It rejects inadequate samples, sparse intervals and stale or unfinished candles. It stops at the first acceptable timeframe and records the successful decision in `candle_period_selections`. It returns a failure if none qualifies.
- `trademinator:sync-ohlcv` splits long ranges into bounded, overlapping pages (90 candles by default, configurable from 10 to 100 with `--page-size`). The same limit is passed to CCXT. It supports incremental refresh of the newest stored candle, gap inspection, up to five repair attempts per page, and queued jobs with retries and backoff. Queued pages run sequentially: each successful job schedules the next. Missing intervals remain missing if the exchange supplies no candle; no volume or price is invented.

## Installation and use

Run `composer install` and `php artisan migrate`. Start a Laravel queue worker if using `--queue` and set `QUEUE_CONNECTION` to a persistent queue driver; `sync` runs inline.

```bash
php artisan trademinator:sync-ohlcv coinbase BTC/USD 1m --from='7 days ago' --incremental --repair-gaps
php artisan trademinator:sync-ohlcv coinbase BTC/USD 1m --from='90 days ago' --queue
php artisan trademinator:select-candle-period coinbase BTC/USD --tick-size=0.01 --threshold=0.7 --coverage=0.8 --minimum=50 --sample=250 --from='7 days ago'
```

Supply `--tick-size` from the market's CCXT tick-size metadata, accounting for that exchange's precision mode. A closed candle has reached its timeframe end at the requested cutoff and at the current time. The selector samples at most the latest 250 closed candles per timeframe; increase `--sample` for a longer window. `--minimum` cannot exceed `--sample`. Coverage is the fraction of expected candles between the first and last sampled observations; gaps may reflect no-trade periods on illiquid markets. A high price score does not override poor coverage.

The `candle_period_selections` table stores the selected exchange/symbol/timeframe, sample bounds, thresholds and quality metrics for audit. It does not place trades. All timestamps in the candle and decision payload are milliseconds since Unix epoch.

For large sync ranges, `--queue` processes one page per job; keep a persistent queue worker running. `QUEUE_CONNECTION=sync` is not accepted with `--queue`. Each page overlaps a few prior candles, so the `fetched` and `missing_ranges` totals in a direct run can count observations in more than one page; database upserts still keep one row per candle. If an exchange enforces a lower per-request maximum, lower `--page-size` accordingly.

## Verification

```bash
composer test
```

The test cases cover precision, calendar boundaries, sparse samples, Coinbase empty windows, recent updates, and selection excluding the active candle. Validate against liquid, moderate, illiquid and borderline real exchange pairs before relying on the default thresholds. PHP and Composer were unavailable in the authoring environment, so the suite could not run there.

## Shared subscription-driven collection

M1 also adds `markets`, `market_subscriptions`, and `market_feeds`. A unique exchange UUID + symbol identifies one market. Each user may subscribe once to a market, and all active subscribers share its feed. Unsubscribing the last user idles the feed; reactivation resumes it. These records carry no billing data. The `MarketSubscriptionEntitlement` interface is the seam for M6 Stripe/PayPal plans, renewals and entitlements; M1 allows every account to subscribe.

After migration, select an exchange that is already configured (the exchange seeder is sufficient), then supply the symbol exactly as CCXT lists it and its positive price tick size:

```bash
php artisan trademinator:market-subscription subscribe user@example.com kraken BTC/USD --tick-size=0.01
php artisan trademinator:market-subscription list user@example.com
php artisan trademinator:market-subscription unsubscribe user@example.com kraken BTC/USD
```

The first due collection chooses the shortest supported quality-qualified completed-candle period using the existing `trademinator:select-candle-period` logic, records the decision, and stores that period on the shared feed. If insufficient history passes quality and coverage, it retries in 15 minutes. The collector fetches a bounded recent window of completed candles with a 90-candle CCXT limit and idempotent upserts into the existing unique `(exchange, symbol, period, microtimestamp)` ticker key. It does not create synthetic candles. Tick size is shared market metadata; subscriptions with a conflicting size are rejected. If exchange precision or tick size changes, update that market intentionally before reselecting a period.

Run the Laravel scheduler and a queue worker on your deployment. Multiple nodes can run `schedule:run` when they share a Redis or database cache store; `onOneServer` and `withoutOverlapping` elect one scheduler, and an atomic database lease and per-market cache lock guard duplicate jobs:

```cron
* * * * * cd /path/to/trader-server && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

```bash
php artisan queue:work --queue=default --timeout=600 --tries=5
```

Use `QUEUE_CONNECTION=database` (or a shared Redis queue) and `CACHE_STORE=redis` (or another shared atomic-lock capable cache). Set the queue connection's `retry_after` to at least 720 seconds, longer than the 600-second job timeout; restart workers after configuration changes. The lease expires after 15 minutes so an abandoned job can be reclaimed. Run `php artisan trademinator:dispatch-market-feeds` manually to inspect dispatch behavior. After initial deployment, check `market_feeds.status`, `selected_period`, `next_pull_at`, `last_error` and `php artisan queue:failed`. The collector stores the selected period and refreshes the latest 90 candle intervals; it does not backfill an arbitrarily old dormant market. Use `trademinator:sync-ohlcv` for historical backfills.

Authenticated users can manage their subscriptions at `/markets`. The subscription page accepts the configured CCXT exchange ID, the exact exchange symbol and tick size; the list shows shared feed state and the selected period. Unsubscribe requests are scoped to the logged-in user. Users can reactivate an inactive subscription through the same add form.

## Outgoing email

For Mailgun transactional email (account verification and password resets), see [MAILGUN.md](MAILGUN.md). Configure `MAIL_MAILER=mailgun`, the verified domain and API key, then run `php artisan trademinator:mailgun-check`.
