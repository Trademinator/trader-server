# M2 — Feature engine and market context

Complete command reference: [CLI.md](CLI.md).

Current package integration: [COMPOSER-PACKAGE-MIGRATION.md](COMPOSER-PACKAGE-MIGRATION.md).
The older installation notes below describe previous M2 releases. The package
replacement added two Composer requirements and introduced feature version `m2-v5`;
follow the integration document for its installation and lockfile steps. The current
feature version is `m2-v6`, which removes the maximum-supply-dependent context metric.

M2 converts M1's stored, completed exchange candles into versioned feature vectors for M3 datasets and M4 KNN. It does not trade, train a model, or change billing. Exchange/CCXT OHLCV remains authoritative. CoinGecko supplies optional, separately timestamped context.

## Install

The original M2 implementation started at commit `038cef67643e6570917fd05c31eb3bcb63b27bbb`. The current ticker-slice source archive is based on GitHub commit `f41a9de8dc338564fed0535fbb16aaa4dee32f05` (2026-09-28), including the newly added `TickerManipulation` trait and the earlier market-review/UI changes.

For an existing installation, extract into a staging directory first. Preserve the deployed `.env`, `APP_KEY`, database, dependencies and runtime `storage/` contents. This update adds no migration, dependency version, cron entry or daemon. The existing compiled frontend assets are included unchanged. The commands below describe general M2 setup, not a request to reset a database.

```bash
composer install
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

Keep the M1 scheduler running with a shared lock-capable cache and a persistent queue. A permanent queue-worker daemon is not required; use the cron-driven `queue:work --stop-when-empty` setup in [CRONTABS.md](CRONTABS.md). M2 queues one feature build per subscribed market/selected period every five minutes. The 600-second worker timeout and queue `retry_after` of at least 720 seconds still apply. No new Composer or npm dependencies are required. `FEATURES_ENABLED=false` disables automatic feature dispatch; explicit build commands remain available.

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

Scheduled context collection runs hourly. Requests batch up to 100 unique resolved coin IDs per quote currency, reuse global/category data, and avoid duplicate samples within the same UTC hour. The manual [fetch-market-context command](CLI.md#trademinatorfetch-market-context) can refresh one mapped coin/quote immediately, including within the current hour. Rolling activity history counts at most one observation per completed UTC hour. Multiple users subscribing to the same market share the same CoinGecko mapping and context stream. HTTP failures, including 429, fail visibly without fabricating snapshots; collection resumes on the next scheduled run. Shared locks prevent simultaneous collectors.

Mappings can be inspected in `coin_gecko_market_mappings`. `pending` means awaiting resolution, `resolved` is usable, `ambiguous` requires an explicit future/admin mapping decision, `unmapped` means no exact symbol match was found, and `unsupported` currently means the market symbol is not a plain spot `BASE/QUOTE` pair.

Endpoints and authentication are based on the official CoinGecko API endpoints already used by M2: search, coin detail/category metadata, global market data, coin markets, and coin categories.

## Data contract

`market_features` has a unique `(exchange, symbol, period, microtimestamp, version)` key. `App\Models\MarketFeature` exposes the JSON `payload` as an array. Repeated builds update the same rows and preserve their UUIDs. `market_context_snapshots` stores append-only observed context, provider timestamps in the payload, and UUID references from feature rows. Source candle decimal strings remain unchanged. Technical indicators use BCMath at their explicit calculation scales and are stored as decimal strings; conversion to floating point occurs at the existing ML/context boundary, including bounded `tanh` normalization, not inside the indicator recurrences. Identical results require the same trait version, parameters, decimal-scale setting and candle history.

Each feature payload contains:

- `version`: `m2-v6`, removing `context.circulating_fraction` from the full schema. The full vector now contains 18 technical and 9 context features. Technical calculations and the other context definitions retain the published-package implementation and precision contract. See [package integration](COMPOSER-PACKAGE-MIGRATION.md).
- Existing `m2-v1` through `m2-v5` rows may coexist in `market_features`; M2/M3 queries select `FeatureEngine::VERSION`. Rebuilding creates `m2-v6` rows without rewriting older contracts or reusing old-version checkpoints. Existing frozen datasets and models stay unchanged; rebuild features and create new datasets/models for the new version. Remove the retired key from any custom schema.
- `microtimestamp`: candle opening time in milliseconds; `available_at_ms`: candle closing time.
- `history_start_ms`: beginning of the uninterrupted candle segment used to seed calculations.
- `indicators`: named raw technical indicator values, with `null` during warm-up. Trait-backed decimal results are stored as decimal strings so BCMath precision is not lost before normalization.
- `features`: ordered named normalized features; `keys` and `vector` provide a matching KNN schema.
- `missing`: names of null features; `technical_ready`: core technical warm-up completed; `context_ready`: every context field present; `ready`: no feature is missing.
- `context_snapshot_id`: exact observed snapshot, or null.

Every continuous feature lies in `[0, 1]`; direction fields use `-1`, `0`, `1`. Never feed nulls directly to KNN or silently convert them to zero. M3 should explicitly select a feature schema and filter/impute under training-only rules. A technical-only schema is valid when CoinGecko is disabled. Keep schema/version and history origin with each dataset. Missing category, long return history, or context will keep the full vector's `ready` false intentionally. Maximum supply is not used: uncapped assets can have all nine context features ready, and no circulating/total-supply replacement is introduced.

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
| `context.volume_share` | Coin volume / global volume in the same currency; liquidity/activity proxy, not order-book depth |

Ratios representing fractions are clamped to `[0,1]`. `FeatureEngine::calculateSlice()` calls the ordinary `Technical` array methods directly: `ema()`, `rsi()`, `sto_rsi()`, `cci()`, `atrp()`, `roc()`, `volume_activity()` and `candle_geometry()`. All former indicator methods ending in `_next` have been removed, not renamed or left as a second implementation. The engine applies versioned ML normalization after the shared trait calculations.

## Timestamp indexing and ordinary indicator slices

`Trademinator\Indicators\Traits\TickerManipulation` from `trademinator/indicators` owns naming, indexing and slicing. `Technical` uses this trait, preserving the user's separation of ticker manipulation from indicator mathematics. There are no database or Laravel dependencies inside either slicing helper.

`normalize_ticker($tickers, true)` turns each CCXT OHLCV row `[milliseconds, open, high, low, close, volume]` into named decimal-string values and keys the **outer** array by integer Unix seconds. For example, timestamp `1790620264123` produces `$tickers[1790620264]['high']`, while `$tickers[1790620264]['microtimestamp']` stays `1790620264123`. `human_date` uses the trait's `YmdHis` format in UTC. Numeric inner keys are removed. With `$reindex = false`, existing outer keys are preserved.

Use `normalize_ticker($tickers, true, 'milliseconds')` when distinct sub-second rows must remain separate. Duplicate outer timestamps, including collisions after conversion to seconds, are rejected rather than silently overwriting a row. Validation builds a separate result and leaves the original input intact on failure. Rows are independent copies, not aliases to a reused loop reference. Decimal strings and scientific notation are preserved/expanded without `sprintf('%f')` rounding; a float already returned by CCXT cannot recover precision that was lost before normalization.

Every production OHLCV request currently goes through `TickerRepository::fetch()`. Its next statement after `fetch_ohlcv()` calls `normalize_ticker()` with reindexing enabled, before logging or consuming the page. `OhlcvNormalizer`, the older `Indexing::normalize()` adapter and the namespaced helper delegate to the same implementation. Exchange paging and database keys still use the original `microtimestamp` in milliseconds; no database migration is needed.

### Calling the slice API

```php
$technical = new class
{
    use \Trademinator\Indicators\Traits\Technical;
};

$technical->normalize_ticker($tickers, true);
$calculated = [];
foreach ($technical->ticker_slide(
    $tickers,
    function (array &$slice) use ($technical): void {
        $technical->ema($slice, 24);
    },
    period: 24,
    multiplier: 3,
    batchSize: 500,
) as $timestamp => $ticker) {
    $calculated[$timestamp] = $ticker; // Or persist this newly calculated row.
}
```

`ticker_slide()` combines retained overlap with each new batch, invokes the real array method through the callback, and emits **only the new rows**. Keys and chronological order are preserved. It keeps its carry through `ticker_slice()`; do not use `array_merge()` on integer timestamp keys because that renumbers them. The optional `continuous` callback allows callers to reset at a gap or a new independent history segment. FeatureEngine supplies that check for closed-candle history.

`ticker_slice($calculated, 24, 3)` normally retains the final 72 rows; multiplier 2 retains 48. During initial warm-up it can retain up to `(multiplier + 2) * period` rows so the first retained boundary is mature. Recursive EMA/SMMA and Wilder averages retain their already-calculated boundary values in those rows. They are **not cold-reseeded** from 48/72 raw prices: two or three periods alone cannot guarantee the same EMA as the complete history. Internal boundary metadata is stripped from rows emitted by `ticker_slide()` and never stored by FeatureBuilder.

To continue manually, calculate the full initial prefix, obtain `ticker_slice()` from that calculated prefix, append new **raw** candles using their timestamps, then call `ema()` on that array. Do not edit the retained calculated overlap; a correction to old candles requires replaying the history. A missing mature seed or insufficient window overlap raises an exception rather than emitting an approximation. `period` must cover all indicator lookbacks used by the callback, including dependencies. Parity regression coverage covers the feature-engine methods and EMA24; unrelated legacy indicators have not received a complete new mathematical audit in this update.

SMA retains an exact working sum. Published indicator precision follows the package `PrecisionPolicy` (minimum 16, four guard digits, maximum 32 by default), not the removed exchange constant. Default ATR/ATRP use SMMA, while explicit SMA/EMA results have mode-qualified keys (`atrp(14,sma)`, for example) so two methods cannot overwrite each other's cached results.

## Time integrity and limitations

- Only completed candles are emitted. Duplicate/unsorted timestamps, invalid OHLC bounds, nonfinite fields, and negative volume are rejected.
- Gaps reset indicator state and elapsed-time return history; no candles are fabricated. Core warm-up requires 28 consecutive candles. Long-horizon returns require their actual elapsed history.
- Context is eligible only if **received** at or before candle close, not merely labelled by the provider with an earlier time. A collection today can never enrich yesterday's historical vectors. The first usable context appears on a subsequent closed candle.
- Observations expire after two hours and are also bounded by the provider timestamps' age. Current CoinGecko endpoints cannot backfill point-in-time historical context. Accumulate it going forward.
- Appending future candles does not change prior feature values. Correcting historical source candles can legitimately change subsequent derived features; builds replay all retained history. M3 will need immutable dataset snapshots for experiment reproducibility.
- Replays read candles/context in bounded database pages and calculate up to 500 new candles with a 60-row technical overlap (up to 100 initial warm-up rows). They separately retain up to 30 days of elapsed-return anchors and upsert in batches of 100. History is still replayed from the earliest stored candle for reproducible seeds. Slice carry is in-memory only, not a persisted checkpoint engine. A 540-second guard prevents a build outliving its shared 720-second lock. Very large histories need an offline dataset/checkpoint workflow in M3; monitor failed queue jobs.
- M2 does not fetch missing exchange history. Use the M1 sync command before rebuilding features.

## Verification

```bash
php artisan test --filter='TickerManipulationTest|TechnicalSliceTest|TechnicalIndicatorsTest|OhlcvNormalizerTest|FeatureEngineTest|TickerNormalizationTest|MarketFeaturesTest'
php artisan test
```

Tests cover causal prefix invariance, numerical examples, neutral flat markets, warm-up, gap resets, completed candles, timestamp validation, elapsed returns, stale/future context, idempotent persistence, raw-candle preservation, exact quote mapping, CoinGecko batching/authentication, hourly deduplication, and HTTP failure behavior.

Historical validation reported for the original M2 release (not rerun for this archive): **85 tests passed, 341 assertions**, using PHP 8.4.26 and SQLite; Pint passed for that earlier release. Test HTTP calls are mocked and unexpected Laravel HTTP requests are blocked, including the existing password-breach lookup. MariaDB-specific behavior and authenticated live CoinGecko responses were not exercised in this environment.


For the current ticker-slice archive, a standalone PHP decimal harness executed **47 selected unit-test bodies/dataset cases and 7,005 assertions**, with all passing. It used a GMP-backed BCMath-compatible verification layer, **not native PHP BCMath and not Pest/Laravel**. The verification backend was separately checked against Python Decimal for 3,500 arithmetic cases. The shim is not included in the application archive and is not a production fallback. Strict comparisons covered batch sizes 1, 7, 48, 72 and 500, EMA24 continuation, all feature-path indicators, gap resets, key preservation and normalization precision. All 10 existing frontend script tests also passed. Full PHP syntax checks and their count are recorded in `RELEASE.json`.

Native BCMath/Pest, the Laravel repository integration regression, MariaDB, concurrent workers and live exchanges were not exercised in this build environment: BCMath, Composer and `vendor/` were unavailable. Run the native command above in a test checkout with Composer dependencies and BCMath before production deployment. A successful syntax check alone is not runtime verification.
