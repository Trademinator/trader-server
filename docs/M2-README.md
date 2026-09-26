# M2 — Feature engine and market context

Complete command reference: [CLI.md](CLI.md).

M2 converts M1's stored, completed exchange candles into versioned feature vectors for M3 datasets and M4 KNN. It does not trade, train a model, or change billing. Exchange/CCXT OHLCV remains authoritative. CoinGecko supplies optional, separately timestamped context.

## Install

This patch is based on repository commit `038cef67643e6570917fd05c31eb3bcb63b27bbb`.
Apply the files from the archive at the repository root (review changes first if your checkout has newer edits).

```bash
composer install
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

Keep the M1 scheduler running with a shared lock-capable cache and a persistent queue. A permanent queue-worker daemon is not required; use the cron-driven `queue:work --stop-when-empty` setup in [contact.md](contact.md). M2 queues one feature build per subscribed market/selected period every five minutes. The 600-second worker timeout and queue `retry_after` of at least 720 seconds still apply. No new Composer or npm dependencies are required. `FEATURES_ENABLED=false` disables automatic feature dispatch; explicit build commands remain available.

Build existing stored history immediately:

```bash
php artisan trademinator:build-features kraken BTC/USD 1m
php artisan trademinator:dispatch-market-features
```

The legacy `trademinator:create-indicators` command now delegates to M2. Its optional `from` limits output writes; earlier stored candles still seed indicators. Its optional `to` is a completed-candle cutoff, capped at now. It no longer prints candle dumps or writes indicators into the raw ticker payload.

## CoinGecko setup

Set `COINGECKO_ENABLED=true`, `COINGECKO_API_KEY=...`, and `COINGECKO_PRO=false` (Demo) or `true` (Pro). The key stays in an HTTP header. Coin IDs are no longer configured in `config/features.php`.

CoinGecko context is subscription-driven:

1. Creating a `MarketSubscription` emits `MarketSubscriptionCreated`.
2. `EnsureCoinGeckoMarketMapping` creates one `coin_gecko_market_mappings` row for the shared market, not one row per user.
3. The hourly `trademinator:collect-market-context` command backfills any active subscriptions that predate this migration and resolves pending mappings through CoinGecko.
4. Automatic resolution accepts only one exact symbol match. Multiple exact matches are marked `ambiguous`; zero matches are marked `unmapped`. Trademinator does not guess a coin ID.
5. Resolved mappings store the exact quote currency derived from the exchange's `BASE/QUOTE` spot symbol. BTC/USDT therefore remains USDT and is never silently substituted with USD.

The resolver also attempts to map the coin's first CoinGecko category to a category ID for sector/category momentum. Category metadata is optional; failure to resolve a category does not block an otherwise unambiguous coin mapping. Derivative symbols such as `BTC/USD:USD` are marked `unsupported` for automatic mapping until a settlement/conversion design is added.

```bash
php artisan migrate --force
php artisan config:cache
php artisan trademinator:collect-market-context
```

Context collection runs hourly. Requests batch up to 100 unique resolved coin IDs per quote currency, reuse global/category data, and avoid duplicate samples within the same UTC hour. Multiple users subscribing to the same market therefore share the same CoinGecko mapping and context stream. HTTP failures, including 429, fail visibly without fabricating snapshots; collection resumes on the next scheduled run. Shared locks prevent simultaneous collectors.

Mappings can be inspected in `coin_gecko_market_mappings`. `pending` means awaiting resolution, `resolved` is usable, `ambiguous` requires an explicit future/admin mapping decision, `unmapped` means no exact symbol match was found, and `unsupported` currently means the market symbol is not a plain spot `BASE/QUOTE` pair.

Endpoints and authentication are based on the official CoinGecko API endpoints already used by M2: search, coin detail/category metadata, global market data, coin markets, and coin categories.

## Data contract

`market_features` has a unique `(exchange, symbol, period, microtimestamp, version)` key. `App\Models\MarketFeature` exposes the JSON `payload` as an array. Repeated builds update the same rows and preserve their UUIDs. `market_context_snapshots` stores append-only observed context, provider timestamps in the payload, and UUID references from feature rows. Source candle decimal strings remain unchanged; indicator calculations use double precision and are not execution prices.

Each feature payload contains:

- `version`: `m2-v1`, identifying formulas, ordering, and normalization.
- `microtimestamp`: candle opening time in milliseconds; `available_at_ms`: candle closing time.
- `history_start_ms`: beginning of the uninterrupted candle segment used to seed calculations.
- `indicators`: named raw indicator values, with `null` during warm-up.
- `features`: ordered named normalized features; `keys` and `vector` provide a matching KNN schema.
- `missing`: names of null features; `technical_ready`: core technical warm-up completed; `context_ready`: every context field present; `ready`: no feature is missing.
- `context_snapshot_id`: exact observed snapshot, or null.

Every continuous feature lies in `[0, 1]`; direction fields use `-1`, `0`, `1`. Never feed nulls directly to KNN or silently convert them to zero. M3 should explicitly select a feature schema and filter/impute under training-only rules. A technical-only schema is valid when CoinGecko is disabled. Keep schema/version and history origin with each dataset. Missing max supply (for uncapped assets), category, long return history, or context will keep the full vector's `ready` false intentionally.

## Indicators and normalization

Signed bounded normalization is `B(x,s) = 0.5 + 0.5*tanh(x/s)`. Zero change is 0.5; extremes approach 0 or 1. Scales below are fixed versioned engineering defaults, not fitted predictive thresholds.

| Feature | Definition |
| --- | --- |
| `trend.ema_3_12` | `B((EMA3−EMA12)/close, 0.05)`; EMA seeds with the period's SMA, then uses `2/(p+1)` |
| `trend.direction` | Sign of EMA3−EMA12 |
| `return.4`, `return.12` | `B(close/close[p bars ago]−1, 0.1)` |
| `momentum.rsi_3`, `momentum.rsi_14` | Wilder RSI divided by 100; flat gain/loss maps to 0.5 |
| `momentum.stoch_rsi_14` | RSI14 position within its last 14 valid values; flat range maps to 0.5; unsmoothed fast K |
| `momentum.cci_20` | Typical-price CCI with mean absolute deviation, mapped by `B(CCI,200)` |
| `volatility.atrp_3`, `volatility.atrp_14` | Wilder ATR/close divided by 0.1 and capped at 1; first true range is high−low |
| `volume.activity_20` | `B(volume/SMA20(volume)−1,2)`; all-zero window maps to 0.5 |
| `candle.body`, `upper_wick`, `lower_wick` | Fractions of high−low; all zero for a flat candle |
| `candle.direction` | Sign of close−open |
| `return.24h`, `return.7d`, `return.30d` | Exact elapsed-time close return, normalized with `B(return,0.1)`; no exact anchor means null; 30d is not a calendar month |
| `context.global_regime` | `B(global market-cap 24h percentage change,10)` |
| `context.btc_dominance` | BTC market-cap percentage / 100 |
| `context.btc_dominance_change` | `B(dominance change in percentage points versus ~24h ago,5)`; prior observation may be up to 2 hours older than target |
| `context.activity` | Volume/market-cap ratio `r`, mapped to `r/(1+r)` |
| `context.activity_deviation` | `B(z-score of activity versus previous 168 observed hourly samples,3)`; needs at least 24 prior valid samples |
| `context.category_momentum` | `B(category market-cap 24h percentage change,10)` |
| `context.price_deviation` | `B(exchange close / aggregate same-quote price−1,0.05)` |
| `context.market_cap_share` | Coin market cap / global market cap in the same currency |
| `context.circulating_fraction` | Circulating supply / max supply; unknown/uncapped max supply stays null |
| `context.volume_share` | Coin volume / global volume in the same currency; liquidity/activity proxy, not order-book depth |

Ratios representing fractions are clamped to `[0,1]`. Legacy `Technical` trait functions remain available and unchanged; the M2 engine uses its own explicitly versioned warm-up and smoothing contract to avoid inheriting partially seeded legacy values.

## Time integrity and limitations

- Only completed candles are emitted. Duplicate/unsorted timestamps, invalid OHLC bounds, nonfinite fields, and negative volume are rejected.
- Gaps reset indicator state and elapsed-time return history; no candles are fabricated. Core warm-up requires 28 consecutive candles. Long-horizon returns require their actual elapsed history.
- Context is eligible only if **received** at or before candle close, not merely labelled by the provider with an earlier time. A collection today can never enrich yesterday's historical vectors. The first usable context appears on a subsequent closed candle.
- Observations expire after two hours and are also bounded by the provider timestamps' age. Current CoinGecko endpoints cannot backfill point-in-time historical context. Accumulate it going forward.
- Appending future candles does not change prior feature values. Correcting historical source candles can legitimately change subsequent derived features; builds replay all retained history. M3 will need immutable dataset snapshots for experiment reproducibility.
- Replays read candles/context in bounded database pages, retain 20 technical bars plus up to 30 days of return anchors, and upsert in batches of 100. This initial implementation trades replay work for deterministic seeding. It is not an incremental checkpoint engine. A 540-second guard prevents a build outliving its shared 720-second lock. Very large histories need an offline dataset/checkpoint workflow in M3; monitor failed queue jobs.
- M2 does not fetch missing exchange history. Use the M1 sync command before rebuilding features.

## Verification

```bash
php artisan test --filter='FeatureEngine|MarketFeatures'
php artisan test
```

Tests cover causal prefix invariance, numerical examples, neutral flat markets, warm-up, gap resets, completed candles, timestamp validation, elapsed returns, stale/future context, idempotent persistence, raw-candle preservation, exact quote mapping, CoinGecko batching/authentication, hourly deduplication, and HTTP failure behavior.

Verified during implementation: **85 tests passed, 341 assertions**, using PHP 8.4.26 and SQLite; Pint passed for every changed PHP file. Test HTTP calls are mocked and unexpected Laravel HTTP requests are blocked, including the existing password-breach lookup. MariaDB-specific behavior and authenticated live CoinGecko responses were not exercised in this environment.
