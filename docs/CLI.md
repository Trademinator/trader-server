# Trademinator CLI reference

This is the canonical reference for every registered `trademinator:*` Artisan command. Update it in the same change whenever a command is added, renamed, removed, or its behavior, arguments, options, defaults, or side effects change. `AGENTS.md` records this requirement; `TrademinatorCliDocumentationTest` checks command coverage, lowercase names, signatures, and descriptions.

Run commands from the Laravel project root using the same PHP version and configuration as the application:

```bash
php artisan list trademinator
php artisan help trademinator:sync-ohlcv
```

If the exchange selector is empty after data loss, use the read-only `trademinator:exchange list` to inspect configured entries, then `trademinator:exchange add` for missing exchanges. The revised `php artisan db:seed --class=ExchangeSeeder --force` can fill the full installed CCXT catalogue while preserving existing records. See [M3 R1 recovery and test isolation](M3-R1-RECOVERY.md) before running tests from older packages.

For pair-loading errors, CCXT memory use and Alpaca credentials, see [M3 R2 markets repair](M3-R2-MARKETS.md). R3 extends that tool with source-bound access reviews and daily refresh. See [R3 access registry](M3-R3-ACCESS.md) and the [complete classification matrix](CCXT-OHLCV-CLASSIFICATION.md).

## Offline exchange metadata

Invocation: `php scripts/build-exchange-metadata.php [--runtime|EXCHANGE_ID]`

With no arguments, rebuild the release bundle at `resources/data/ccxt-exchanges.json`. `--runtime` instead writes `storage/app/private/ccxt-exchanges.json` and is run automatically by Composer's `post-autoload-dump` hook (install, update and dump-autoload). Supply at most one argument. Requires installed Composer dependencies, CLI PHP with the normal CCXT extensions, process creation (`proc_open`), writable `storage/framework/` for the local lock, and a writable output directory. No Laravel boot, credentials, HTTP or database access.

Every adapter is inspected in a separate PHP child limited to 128 MiB and 30 seconds. Classifications come from `resources/data/ccxt-access-reviews.json` only when source fingerprints match. New/changed/uninspectable adapters become unknown; emulated/unadvertised OHLCV is also excluded. Inspection failures do not preserve old public labels: the snapshot records `inspection_failed`, and the output lists the adapter as needing review. The complete snapshot replaces the previous file atomically; filesystem failures exit nonzero. Cache entries are checked against the current metadata/source before use, so old public entries cannot bypass the review gate.

`EXCHANGE_ID` is the internal worker mode: print one installed adapter's description, required credential **names**, inherited source-file hashes and fingerprint as JSON. It does not replace a file or approve a review. Unknown IDs fail. The hook does not update CCXT itself; that remains an explicit Composer dependency change. To synchronize missing database entries too, use `trademinator:refresh-exchanges` below.

```bash
php scripts/build-exchange-metadata.php
php scripts/build-exchange-metadata.php --runtime
php scripts/build-exchange-metadata.php binance
```

Names use lowercase kebab-case. Argument values retain their required case: `BTC/USD` is an exchange symbol, `1m` is one minute, and `1M` is one month. Never lowercase symbol or timeframe values automatically. Legacy market-data commands use PHP date parsing; quote dates containing spaces and specify a timezone. M3 research commands require explicit UTC dates or Unix milliseconds, as described below. Persisted market timestamps are Unix milliseconds.

In signatures below, `{name}` is required, `{name?}` is optional, `{--flag}` is boolean, and `{--option=value}` supplies a default. These are Laravel signature declarations, not literal shell braces. Commands also support Laravel's standard help, verbosity, environment, and non-interactive options; see `--help` for the runtime's complete list.

## Command index

- [`trademinator:knn-build`](#trademinatorknn-build) — Build and validate M4 intelligence
- [`trademinator:signal`](#trademinatorsignal) — Explain the latest closed-candle signal
- [`trademinator:model-info`](#trademinatormodel-info) — Verify and inspect a model
- [`trademinator:dispatch-market-intelligence`](#trademinatordispatch-market-intelligence) — Queue weekly shared-market training
- [`trademinator:derive-timeframe`](#trademinatorderive-timeframe) — Derive larger closed candles using the M2 pipeline

- [`trademinator:refresh-exchanges`](#trademinatorrefresh-exchanges) — Refresh installed CCXT access classifications and missing database entries

- [`trademinator:backtest`](#trademinatorbacktest) — Run purged walk-forward baseline evaluation on a frozen M3 dataset
- [`trademinator:build-dataset`](#trademinatorbuild-dataset) — Freeze M2 features and fee-aware future labels into an immutable M3 dataset
- [`trademinator:dataset-info`](#trademinatordataset-info) — Verify an M3 dataset checksum and display its frozen manifest

- [`trademinator:build-features`](#trademinatorbuild-features) — Replay stored completed candles into versioned, causal M2 features
- [`trademinator:collect-market-context`](#trademinatorcollect-market-context) — Collect timestamped CoinGecko context for subscribed markets
- [`trademinator:create-indicators`](#trademinatorcreate-indicators) — Build M2 indicators and feature vectors from stored completed candles
- [`trademinator:dispatch-market-features`](#trademinatordispatch-market-features) — Queue M2 feature builds for subscribed markets with selected candle periods
- [`trademinator:dispatch-market-feeds`](#trademinatordispatch-market-feeds) — Queue due market feeds with at least one active subscription
- [`trademinator:exchange`](#trademinatorexchange) — Add, edit, list, or delete configured CCXT exchanges
- [`trademinator:fetch-ohlcv`](#trademinatorfetch-ohlcv) — Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame
- [`trademinator:mailgun-check`](#trademinatormailgun-check) — Check Mailgun settings and optionally send a test message
- [`trademinator:market-subscription`](#trademinatormarket-subscription) — Manage user subscriptions that drive shared market collection
- [`trademinator:select-candle-period`](#trademinatorselect-candle-period) — Select the shortest sufficiently informative candle period
- [`trademinator:sync-ohlcv`](#trademinatorsync-ohlcv) — Fetch and upsert exchange candles, optionally inspect and repair missing ranges

## trademinator:build-features

Description: Replay stored completed candles into versioned, causal M2 features

Signature: `trademinator:build-features {exchange} {symbol} {period}`

Build normalized M2 features from stored completed candles for one exchange/symbol/period. All three arguments are required. Writes `market_features` without changing raw `tickers`. Replays retained history for deterministic indicator seeding; gaps restart warm-up and missing context stays null. Does not fetch exchange candles or train KNN. Works when automatic feature dispatch is disabled.

```bash
php artisan trademinator:build-features kraken BTC/USD 1m
```

## trademinator:collect-market-context

Description: Collect timestamped CoinGecko context for subscribed markets

Signature: `trademinator:collect-market-context`

Collect CoinGecko snapshots for markets that have active subscriptions. No command-specific arguments or options. Creating a `MarketSubscription` emits `MarketSubscriptionCreated`, which creates a pending `coin_gecko_market_mappings` row. The hourly collector also backfills pre-existing active subscriptions, resolves pending mappings using exact CoinGecko symbol matches, and refuses to guess when a symbol is ambiguous. Requires `COINGECKO_ENABLED=true` and an API key. Resolved mappings are shared by all subscribers of the same market; duplicate coin/quote requests are batched and observations are deduplicated within the same UTC hour. Provider failures fail the command; they do not become fabricated values. Scheduled hourly.

```bash
php artisan trademinator:collect-market-context
```

See [M2 setup](M2-README.md#coingecko-setup).

## trademinator:create-indicators

Description: Build M2 indicators and feature vectors from stored completed candles

Signature: `trademinator:create-indicators {exchange} {symbol} {period} {from?} {to?} {--debug}`

Build the same M2 features as `build-features`, with optional positional date boundaries. Required arguments: `exchange`, `symbol`, `period`.

| Argument/option | Default | Meaning |
| --- | --- | --- |
| `from` | All stored history | Only write feature rows at or after this candle-opening time; earlier candles still seed indicators. |
| `to` | Now | Only include candles completed by this time; future values are capped at now. |
| `--debug` | Off | Retained option; currently has no effect in the M2 implementation. |

```bash
php artisan trademinator:create-indicators kraken BTC/USD 1m '7 days ago' now
```

Formerly `trademinator:CreateIndicators`. Update scripts to the new name; the mixed-case name is not registered as an alias.

## trademinator:dispatch-market-features

Description: Queue M2 feature builds for subscribed markets with selected candle periods

Signature: `trademinator:dispatch-market-features`

Queue a shared feature build for each market with an active subscription and a selected candle period. No command-specific arguments or options. `FEATURES_ENABLED=false` makes dispatch a successful no-op. Unique jobs and build locks suppress concurrent duplicate work. Scheduled every five minutes. Use a persistent queue in production; a `sync` queue executes the builds inline.

```bash
php artisan trademinator:dispatch-market-features
```

## trademinator:dispatch-market-feeds

Description: Queue due market feeds with at least one active subscription

Signature: `trademinator:dispatch-market-feeds {--limit=100}`

Queue due market-data feeds that have active subscribers. `--limit` defaults to **100**, and must be an integer from **1 to 1000**. Requires a persistent queue; `sync` and `null` are rejected. Shared database leases and locks coordinate collection across nodes. Scheduled every minute.

```bash
php artisan trademinator:dispatch-market-feeds --limit=100
```

## trademinator:exchange

Description: Add, edit, list, or delete configured CCXT exchanges

Signature: `trademinator:exchange {action : add, edit, delete, or list} {class? : The CCXT exchange ID, such as kraken} {--name= : Exchange display name for add or edit} {--config= : CCXT settings as a JSON object} {--config-file= : Path to a file containing a CCXT JSON object} {--search= : Filter the list by CCXT ID or name} {--force : Skip the deletion confirmation}`

Manage stored CCXT exchange configurations. `action` is required: `add`, `edit`, `delete`, or `list`. The positional `class` is the CCXT exchange ID; required for mutations, omitted for `list`.

| Option | Default | Meaning |
| --- | --- | --- |
| `--name` | CCXT ID when adding | Display name; 1–64 characters. |
| `--config` | `{}` when adding | CCXT settings as a JSON object. |
| `--config-file` | None | Read a JSON object from a readable file up to 64 KiB; mutually exclusive with `--config`. |
| `--search` | None | Filter the list by CCXT ID or display name. |
| `--force` | Off | Skip deletion confirmation. |

```bash
php artisan trademinator:exchange list --search=kraken
php artisan trademinator:exchange add kraken --name=Kraken
php artisan trademinator:exchange edit kraken --config-file=/secure/kraken.json
php artisan trademinator:exchange delete kraken
```

Edits require at least one of name/config/config-file. Active subscriptions block deletion. Deletion removes exchange configuration, markets, inactive subscriptions, and feed records; historical candles remain. Prefer a protected config file for API secrets rather than putting them in shell history. See [exchange details](EXCHANGES-CLI.md).

## trademinator:fetch-ohlcv

Description: Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame

Signature: `trademinator:fetch-ohlcv {exchange} {symbol} {period} {from?} {to?} {--debug}`

Fetch, persist, and print OHLCV for a configured exchange. Required arguments: `exchange`, `symbol`, `period`. The optional positional `from` defaults to **yesterday** and `to` to **now**. `--debug` enables verbose CCXT output. This command calls the existing fetch repository, which saves candles before returning them; it is not a read-only preview.

```bash
php artisan trademinator:fetch-ohlcv kraken BTC/USD 1m 'yesterday' now
```

Laravel also provides `--isolated[=EXIT_CODE]` because this command implements `Isolatable`. Supplying it uses an atomic cache lock; by default a lock conflict exits successfully, or with the supplied code. The default lock is command-wide, not per market. `--no-interaction` disables prompts for missing required arguments.

Formerly `trademinator:FetchOHLCV`; update scripts to the lowercase name. No mixed-case alias is registered. Prefer `sync-ohlcv` for bounded paginated backfills, queueing, incremental updates, and gap repair.

## trademinator:mailgun-check

Description: Check Mailgun settings and optionally send a test message

Signature: `trademinator:mailgun-check {--to= : Send a test email to this address after validating configuration}`

Validate the Mailgun mailer, domain, API secret, endpoint, and sender configuration. With no options this does not send email or verify live delivery. Optional `--to=ADDRESS` validates the recipient and submits a real test message via Mailgun after configuration checks pass.

```bash
php artisan trademinator:mailgun-check
php artisan trademinator:mailgun-check --to=you@example.com
```

Success with `--to` means submitted to Mailgun, not confirmed delivered. See [Mailgun setup](MAILGUN.md).

## trademinator:market-subscription

Description: Manage user subscriptions that drive shared market collection

Signature: `trademinator:market-subscription {action : subscribe, unsubscribe, or list} {user : User email or UUID} {exchange? : CCXT exchange ID} {symbol? : Market symbol, e.g. BTC/USD} {--tick-size= : Minimum price increment from the exchange market metadata}`

Manage a user's subscriptions, which drive shared collection. Required: `action` (`subscribe`, `unsubscribe`, or `list`) and `user` (email or UUID). The positional `exchange` and `symbol` are required for subscribe/unsubscribe; omit them for list.

`--tick-size` has no default and is required when subscribing, including reactivation. Supply the positive minimum price increment from the exchange's market metadata. Conflicting tick sizes on the same shared market are rejected. Unsubscribing makes the user's subscription inactive; it does not delete historical candles. The list includes active and inactive subscriptions. Billing remains separate. R3 requires a current reviewed public/authentication-required adapter when subscribing or reactivating; unknown or removed adapters are rejected before network access. Authentication-required adapters need the required credential fields configured. CLI subscriptions retain their existing market-type behavior; the web form is spot-only. Existing subscriptions are preserved if an adapter later becomes unknown, but shared collection pauses with a blocked status and rechecks in six hours. A fresh current metadata snapshot is required; use `trademinator:refresh-exchanges` after dependency changes.

```bash
php artisan trademinator:market-subscription subscribe user@example.com kraken BTC/USD --tick-size=0.01
php artisan trademinator:market-subscription list user@example.com
php artisan trademinator:market-subscription unsubscribe user@example.com kraken BTC/USD
```

## trademinator:select-candle-period

Description: Select the shortest sufficiently informative candle period

Signature: `trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--coverage=0.8} {--minimum=50} {--sample=250} {--from=7 days ago} {--to=now}`

Fetch candidate candle periods and select the shortest one meeting the quality, coverage, and completed-sample requirements. Required: `exchange`, `symbol`. Prints the decision as JSON and stores a successful decision in `candle_period_selections`; fetched candles are also persisted. Returns failure when no candidate qualifies. A direct CLI decision does not update a shared feed's selected period; the M1 collector manages that field.

| Option | Default | Meaning |
| --- | --- | --- |
| `--periods` | `1m,3m,5m,15m,30m,1h,4h,1d` | Comma-separated candidates; all must be supported by the exchange and Trademinator. |
| `--tick-size` | Required | Positive exchange price increment. |
| `--threshold` | `0.7` | Minimum information-quality score, from 0 to 1. |
| `--coverage` | `0.8` | Minimum candle coverage, from 0 to 1. |
| `--minimum` | `50` | Minimum completed sample size, at least 1. |
| `--sample` | `250` | Maximum recent completed samples; at least `minimum`. |
| `--from` | `7 days ago` | Start of candidate fetch interval. |
| `--to` | `now` | End of interval; completed-candle checks also respect current time. |

```bash
php artisan trademinator:select-candle-period kraken BTC/USD --tick-size=0.01 --periods=1m,5m,15m --minimum=50
```

## trademinator:sync-ohlcv

Description: Fetch and upsert exchange candles, optionally inspect and repair missing ranges

Signature: `trademinator:sync-ohlcv {exchange} {symbol} {period} {--from=7 days ago} {--to=now} {--incremental} {--repair-gaps} {--queue} {--page-size=90}`

Fetch and upsert candles in bounded overlapping pages. Required: `exchange`, `symbol`, `period`. Prints direct-run counts as JSON, or confirms queued processing.

| Option | Default | Meaning |
| --- | --- | --- |
| `--from` | `7 days ago` | Fetch start. |
| `--to` | `now` | Fetch end; initial start must precede end. |
| `--incremental` | Off | Start at the later of the requested start and latest stored candle, refreshing that candle too. |
| `--repair-gaps` | Off | Inspect gaps and make bounded repair attempts; no synthetic candles. |
| `--queue` | Off | Queue sequential page jobs; requires a persistent queue. |
| `--page-size` | `90` | Integer from 10 to 100 passed as the CCXT request limit. |

```bash
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='7 days ago' --incremental --repair-gaps
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='90 days ago' --queue --page-size=90
```

Overlapping pages can count the same candle more than once in totals; unique database keys prevent duplicate rows. Recent fetched candles can still be open; M2 only emits features after candle completion. See [pagination details](SYNC-PAGINATION.md).

## Scheduler and workers

| Command | Schedule |
| --- | --- |
| `trademinator:dispatch-market-feeds` | Every minute |
| `trademinator:dispatch-market-features` | Every five minutes |
| `trademinator:collect-market-context` | Hourly |

Schedules are defined in `routes/console.php`. All use shared-cache scheduler locks. Configure a shared atomic-lock-capable cache and a persistent queue across nodes. Trademinator does not require a permanent worker daemon: configure the scheduler cron and the `queue:work --stop-when-empty` queue-drain cron documented in [CRONTABS.md](CRONTABS.md).

Set the queue connection's `retry_after` to at least 720 seconds. After deploying code or configuration changes, clear/rebuild configuration as appropriate:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan schedule:list
php artisan queue:failed
```

## Where the KNN inputs are prepared

- `app/Domain/Features/FeatureEngine.php`: technical indicators and normalized technical features; `KEYS` defines their stable order.
- `app/Domain/Features/ContextFeatures.php`: normalized CoinGecko features and their ordered `KEYS`.
- **`app/Domain/Features/FeatureBuilder.php`**: combines those feature sets, creates the ordered `keys` and numeric `vector`, records missing values/readiness, and persists each row in `market_features`.
- `app/Models/MarketFeature.php`: reads saved rows with a decoded JSON payload.
- `app/Models/Knowledge.php`: existing knowledge-record model; it does not build a training matrix.

M2 creates one vector per completed candle. M3 freezes selected vectors and future labels in `app/Domain/Research/DatasetSnapshotBuilder.php`; each JSONL row contains an ordered `vector` and a separate `label`. The manifest records the identical `keys` order and feature/label versions for the entire dataset. `DatasetStore.php` verifies and loads that snapshot, and `WalkForward.php` supplies chronological training/test indices after purging unavailable training outcomes. No scaler is fitted: M2's fixed normalization is retained, and rows missing selected values are dropped explicitly.

M4 will add the Rubix KNN trainer, fitted preprocessing where needed, model registry, and prediction. M3's majority/trend/BUY/HODL strategies are evaluation baselines. See [M3 setup and data contract](M3-README.md).

## trademinator:build-dataset

Description: Freeze M2 features and fee-aware future labels into an immutable M3 dataset

Signature: `trademinator:build-dataset {exchange} {symbol} {period} {--schema=core} {--features=} {--horizon=12} {--fee-bps=10} {--slippage-bps=5} {--min-return-bps=10} {--from=} {--to=} {--as-of=}`

Required arguments are the exact CCXT exchange ID, symbol, and supported candle period. Requires migrated research tables, stored completed exchange candles, and current M2 features. No network access, subscription change, order placement, or model training occurs. Each successful invocation creates a new UUID snapshot in `research_datasets` and private files under `storage/app/private/research/<dataset-id>/`. Prints the JSON manifest including the ID. Empty, malformed, or oversized builds fail without publishing a dataset. Defaults limit builds to 50,000 eligible rows via `config/research.php`.

| Option | Default | Meaning |
| --- | --- | --- |
| `--schema` | `core` | `core`: 15 technical features without long elapsed returns; `technical`: all 18 technical features; `full`: technical plus all 10 CoinGecko context features; `custom`: exact keys from `--features`. See [Choosing a feature schema](#choosing-a-feature-schema) for the feature lists and history requirements shared with M4. |
| `--features` | None | Comma-separated ordered M2 keys; required only for `custom`, rejected with other schemas. Must be nonempty, unique, known keys. Missing selected values cause a row to be dropped, never imputed. |
| `--horizon` | `12` | Integer 1–10,000 future candles; enter at next open and exit at the close of the horizon-th subsequent candle. |
| `--fee-bps` | `10` | Fee per side, in basis points. 10 bps = 0.10%; use the actual exchange/account fee. |
| `--slippage-bps` | `5` | Adverse price slippage per side; 5 bps = 0.05%. |
| `--min-return-bps` | `10` | Strict minimum net return beyond costs to label BUY or SELL; equality maps to HODL. |
| `--from` | Earliest stored feature | Inclusive signal/decision time lower bound, not candle opening time. |
| `--to` | As-of cutoff | Inclusive signal/decision time upper bound; outcomes may occur later, up to `--as-of`. |
| `--as-of` | Now | Latest observable candle-close time for label outcomes, capped at now. Does not recreate historical provider data or revisions. |

All basis-point inputs must be numeric, finite, at least zero and below 10,000. All three time options accept `YYYY-MM-DD` (UTC midnight), `YYYY-MM-DDTHH:MM:SSZ`, or nonnegative Unix milliseconds. Relative/local-time strings are rejected. Future `--to`/`--as-of` values are capped; the effective values are recorded in the manifest. `--from` must not exceed the effective `--to`.

```bash
php artisan trademinator:build-features kraken BTC/USD 1m
php artisan trademinator:build-dataset kraken BTC/USD 1m --horizon=12 --fee-bps=10 --slippage-bps=5 --min-return-bps=10 --from=2026-09-01 --to=2026-09-20 --as-of=2026-09-21
php artisan trademinator:build-dataset kraken BTC/USD 1h --schema=custom --features=trend.direction,momentum.rsi_14,context.btc_dominance --horizon=6
```

Runs on demand; no scheduler or queue entry. Uses the same shared-cache market/period lock as M2 feature builds; a concurrent build causes a visible failure and should be retried later. Builds stop after 540 seconds, before the 720-second lock expires; reduce the range/horizon if needed. Do not schedule repeated builds unless you intend to retain a new immutable dataset every time. See [label formulas and storage](M3-README.md).

## trademinator:dataset-info

Description: Verify an M3 dataset checksum and display its frozen manifest

Signature: `trademinator:dataset-info {dataset}`

The required `dataset` is the UUID printed by `build-dataset`, not a path. Requires both its database record and private snapshot directory. Reads and verifies the manifest, SHA-256 checksum, row count, chronological order and row contract, then prints the manifest as JSON. Read-only; unknown IDs, corrupt/missing files and datasets over `research.max_rows` fail. No command-specific options or schedule.

```bash
php artisan trademinator:dataset-info DATASET_UUID
```

## trademinator:backtest

Description: Run purged walk-forward baseline evaluation on a frozen M3 dataset

Signature: `trademinator:backtest {dataset} {--strategy=majority} {--train=500} {--test=100} {--gap=0} {--rolling}`

Requires the UUID of a verified M3 dataset and enough mature rows. Uses only the frozen snapshot; changes to live candles/features cannot change a repeated run's metrics. Saves each run with a new UUID to `research_backtests` and a private `backtest-<run-id>.json` in the dataset directory. Prints the report path, run ID, classification summary, trade count and returns. No exchange access or trading side effects. On demand only, no queue/cron changes.

| Option | Default | Meaning |
| --- | --- | --- |
| `--strategy` | `majority` | `majority`: most frequent mature training label, ties prefer HODL then BUY then SELL; `trend`: sign of `trend.direction` (must be selected in the schema); `buy`: always predict BUY; `hodl`: always predict HODL. |
| `--train` | `500` | Minimum eligible training rows, integer 1–50,000. Rolling mode uses exactly this many most recent eligible rows. Expanding mode uses all eligible history. |
| `--test` | `100` | Nonoverlapping test-block size, integer 1–50,000. A final shorter block is included. Up to 1,000 folds per run; increase this option for large datasets. |
| `--gap` | `0` | Additional excluded rows immediately before each test block, integer 0–50,000; measured in selected dataset rows, not elapsed bars. Automatic outcome purging always applies. |
| `--rolling` | Off | Use a fixed-length training window instead of expanding history. |

A training row's label must be known **strictly before** the first test decision. The first test block moves forward until the minimum eligible training count is available; insufficient data is an error. Never shuffle rows or split randomly. Later folds can use earlier test rows only after their labels have matured.

Classification evaluates all test rows. The portfolio is a separate spot-only baseline: start in cash, enter on BUY, hold for the defined horizon, exit, and ignore overlapping entry signals across folds. SELL/HODL remain in cash; no short selling is assumed. Fees/slippage use the frozen label settings. The benchmark is the same nonoverlapping fixed-horizon policy with always-BUY signals, not continuous buy-and-hold. Drawdown is measured at realized exits only. Reports include fold intervals, training counts, confusion matrices, per-class precision/recall/F1, macro F1, predictions, equity curves and cost-adjusted returns. These are research baselines, not a trained model or execution simulator.

```bash
php artisan trademinator:backtest DATASET_UUID --train=500 --test=100
php artisan trademinator:backtest DATASET_UUID --strategy=trend --train=500 --test=100 --gap=12 --rolling
```

## trademinator:refresh-exchanges

Description: Refresh CCXT access classifications and add missing exchange entries without deleting data

Signature: `trademinator:refresh-exchanges {--check : Inspect without changing metadata or exchange rows} {--json : Print the complete inspection report as JSON}`

No positional arguments. Inspects the **installed** CCXT catalogue offline; it does not download/update dependencies or make exchange API requests. Requires Composer dependencies, CLI PHP, `proc_open`, writable `storage/framework/`, and the review manifest. Normal mode also requires migrated `exchanges` and cache tables (if using database cache), a working lock-capable cache, and writable `storage/app/private/`. It writes the runtime snapshot atomically and creates only missing exchange rows with empty configuration. It preserves existing IDs, names, settings, users, markets and subscriptions; removed adapters are hidden, never deleted. Daily refresh runs at **03:20 in the application timezone on every node**, under the existing scheduler cron. A local file lock prevents overlapping scans; a shared cache lock coordinates database inserts between nodes. No queue job or permanent daemon is added.

| Option | Default | Meaning |
| --- | --- | --- |
| `--check` | Off | Build/report an in-memory inspection without writing the metadata snapshot or database rows; still acquires the local scan lock. No database connection is needed by this command's check path. |
| `--json` | Off | Print the full report: version, counts, added/removed/changed adapter IDs, review requirements, rows added, and every adapter's capabilities, access states, reasons and source hashes. Contains no credential values. Without it, print a summary table and ID lists. |

Exit 0 means the scan/report completed; unknown adapters are a reported state, not a command failure. `needs_review` excludes adapters reviewed as unsupported, but includes new, changed and failed inspections. Local-lock conflicts, cache/DB/filesystem failures exit nonzero. A failure never approves unknown source. Database additions are idempotent, so retry after fixing a publication failure. Composer automatically refreshes metadata without database access; this command adds the corresponding missing database entries. New/changed adapters remain hidden until their source has been reviewed and the manifest updated; there is deliberately no force-public/auto-approve flag. See [review procedure](M3-R3-ACCESS.md#reviewing-a-ccxt-update).

```bash
php artisan trademinator:refresh-exchanges --check
php artisan trademinator:refresh-exchanges --check --json
php artisan trademinator:refresh-exchanges
```

Manual refresh is appropriate immediately after deployment. The scheduler provides periodic reconciliation; the Composer hook is the primary reaction to dependency changes. See [cron configuration](CRONTABS.md).

## M4 intelligence workflow and upgrade

M4 implements server-side market intelligence. It does not execute trades or expose the M5 Client API. Existing M3 fee-aware research commands retain their behavior; M4 freezes a new dataset with **cost-free semantic labels**. No exchange keys, fees, account balances or client profitability assumptions enter those labels.

Upgrade an existing M3 installation with the new source, keeping its `.env`, database and writable storage. Run `composer install --no-interaction --prefer-dist` (or add `--no-dev` on production), `php artisan migrate --force`, then `php artisan optimize:clear`. Rebuild any configuration/route caches used by the deployment. No dependencies were added. Never run `migrate:fresh` or replace a production database with an archive copy. Configure the intelligence queue worker in [CRONTABS.md](CRONTABS.md) before enabling scheduled training. The new tables are `intelligence_models` and `intelligence_heads`.

Initial workflow, substituting the actual selected period:

```bash
php artisan trademinator:build-features kraken BTC/USD 1m
php -d memory_limit=512M artisan trademinator:knn-build kraken BTC/USD 1m
php artisan trademinator:signal kraken BTC/USD 1m
```

Active market subscriptions now have an **Intelligence** link. The page shows the current signal, reasons for abstaining, emerging-pattern probabilities and later-period validation results. It is restricted to the active subscription owner and does not trigger training during a request.

### Knowledge and validation contract

- Only truly closed candles and fully matured future labels enter frozen knowledge. By default the newest closed feature is reserved for inference. Gaps reset trailing history. Missing selected features drop a row; they are never silently replaced with zeros.
- A bottom is a close in the bottom 20% of the trailing 20 closes; a top is a close in the top 20%. BUY additionally requires a rise greater than 10 basis points by the close 12 subsequent candles later; SELL requires the corresponding fall. Other outcomes are HODL. Flat windows are HODL. These are explicit, versioned training definitions, not claims of universally valid trading rules. See `config/intelligence.php` to change future builds.
- Existing M2 features are reused in their stored order. `trend.direction` and `candle.direction` map from -1/0/1 to 0/0.5/1 at the model boundary; other features remain within 0–1. M2 artifacts are unchanged. Core is the default schema; `full` includes available CoinGecko context and may drop many rows when context history is incomplete.
- KNN uses RMS distance on normalized vectors, a maximum distance of 0.25, inverse-distance weights, at least 3 effective neighbors and confidence at least 0.6. Effective count is `(sum(weights)^2)/sum(weights^2)`. Exact matches alone vote when present. Ties, distant evidence and insufficient effective support yield `hodl`, confidence 0 and a reason. UI displays HODL as HOLD. Confidence is agreement times similarity, **not** a calibrated profit probability.
- Tuning uses rolling chronological folds, default 250 mature training rows and 100 test rows. Outcome endpoints must be strictly before a fold's first decision. `Kmax = min(floor(sqrt(training rows)), k_cap)`; default cap 65. Coarse candidates are refined near the best eligible value. Semantic directional precision ranks first, then confidence, coverage and stability; excessive top/bottom contradictions disqualify a candidate. Defaults require at least 50 validation rows, 5 directional predictions, 55% semantic precision, 1% directional coverage and at most 5% contradictions.
- The final 20% is reserved for a later evaluation block and never selects K. It must also pass the evidence gates. The published rolling knowledge then contains the most recent 250 mature rows, matching evaluation window size. A rejected build publishes an abstaining head so an older model is not silently presented as newly validated. Historical model artifacts remain available by ID.
- Default snapshot size is bounded to the most recent 3,000 eligible feature timestamps. Explicit oversized datasets fail instead of truncating silently. Application checks bound training to 480 seconds, with 600-second queue timeout and 720-second locks. Reduce the range or training window when needed. Models expire after 14 days; features older than 2 candle periods cannot issue a signal. Raw source/feature mismatches, changed feature versions, or corrupt artifacts fail closed.

### Pattern prediction and chronological stacking

The first catalogue contains bullish/bearish engulfing and gap-free morning/evening star variants appropriate to continuous crypto candles. An initial long candle has stored normalized body at least 0.5, opposing the predicted completion direction, with compatible stored trend direction. Stars add a small middle candle (body at most 0.3). Engulfing completes when the next candle reverses direction and covers the first body. A star completes when its third candle reverses direction and closes beyond the first body's midpoint. These definitions are versioned in `PatternCatalog`; unfinished legacy trait routines are not enabled.

Candidates contain type, total length, current stage, progress and similarity. Pattern vectors reuse the selected normalized M2 vector plus length/stage/progress/similarity. Only the target test examines future candle geometry; no second technical-indicator implementation is introduced. Both completions and failures are sampled, and a candidate's label becomes available only after its remaining candles close.

The first 40% of a knowledge snapshot supplies pattern samples. For each type, earlier 60% trains Rubix ML Random Forest and weighted KNN; the next 20% fits isotonic probability calibration; the last 20% compares Brier score, log loss and calibration error, with reliability bins. Splits purge outcomes extending into the next block. At least 100 samples, 15 rows per block, and both classes in training/calibration are required by default. A selected model must improve Brier score over the prior completion-rate baseline. Reported comparison metrics describe this historical selection block, not guaranteed future performance. Random Forest is stochastic; persisted artifacts preserve the exact fitted model, but rebuilds can differ.

By default, validated completion probabilities can feed the final KNN alongside a presence flag. Only KNN rows strictly after the pattern model's **entire** evaluation horizon are eligible. This sacrifices history to prevent stacking leakage. No future outcome is fed back as a historical probability. An absent pattern uses probability 0.5 with presence 0; a pattern without a validated model has no probability. No eligible pattern models leaves the original KNN vector unchanged. Set `patterns.as_knn_features` false to expose probabilities separately. Rebuilding preserves the old artifact; it never mutates past snapshots.

Artifacts live under `storage/app/private/intelligence`, with independent database digests verified before decoding. Keep them private and share this directory and `storage/app/private/research` across worker/web nodes. Back up the artifacts and their database records together. There is no upload/import endpoint for arbitrary serialized models. `INTELLIGENCE_ENABLED=false` disables scheduled dispatch; explicit CLI analysis remains available. `INTELLIGENCE_QUEUE` defaults to `intelligence`. Other bounded defaults are in `config/intelligence.php` and apply to new builds; inference uses each model's recorded thresholds.

## trademinator:knn-build

Description: Build closed-candle semantic knowledge and validate KNN and pattern intelligence

Signature: `trademinator:knn-build {exchange} {symbol} {period} {--dataset=} {--schema=core} {--from=} {--to=} {--as-of=}`

Required arguments identify the exact exchange class, symbol and candle period. Requires migrated M2/M3/M4 tables, existing current-version M2 features, a lock-capable cache, and writable private research/model directories. Runs synchronously and offline; use the dedicated queue for scheduled work. Acquires shared per-market locks. Prints the model report as JSON, including model/dataset UUIDs, selected K or null, status, schema, training cutoff, fold reports, held-out metrics and pattern comparisons. Exit 0 includes a completed **abstaining** model; it does not assert a usable trading signal. Invalid data, lock conflicts, corrupt input, compute limits and publication failures exit nonzero.

| Option | Default | Meaning |
| --- | --- | --- |
| `--dataset` | New snapshot | Train a verified frozen M4 semantic dataset UUID for this exact market. Old M3 fee-aware labels are rejected. Cannot combine with date options or non-default schema. |
| `--schema` | `core` | Select the stored M2 inputs: `core` (15 technical features), `technical` (18), or `full` (18 technical + 10 CoinGecko context features). See [Choosing a feature schema](#choosing-a-feature-schema). Custom schemas can be consumed from an explicitly built semantic dataset through the domain service. |
| `--from` | Bounded recent history | Inclusive decision-time start. |
| `--to` | Training cutoff | Inclusive decision-time end, clamped to the cutoff. |
| `--as-of` | Before newest closed feature | Maximum time at which all training outcomes must have become available; capped at now. Explicit values can require waiting for a later candle before inference. |

Dates accept UTC `YYYY-MM-DD`, `YYYY-MM-DDTHH:MM:SSZ` or nonnegative Unix milliseconds. Relative dates are rejected. Labels and tuning parameters come from `config/intelligence.php`. Each manual build creates immutable dataset/model artifacts and updates the market/period head, unless the build has an older training cutoff than the existing head. A failure never replaces the head. Weekly queued builds additionally carry a unique database generation key for retry deduplication. Dataset snapshots are retained even when subsequent training fails, so their IDs can be investigated and reused. No orders or external API calls.

```bash
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m
php artisan trademinator:knn-build kraken BTC/USD 1h --schema=full --from=2026-08-01 --as-of=2026-09-28
php artisan trademinator:knn-build kraken BTC/USD 1m --dataset=DATASET_UUID
```

### Choosing a feature schema

`--schema` chooses which existing M2 feature values describe each candle to the model. These values are the inputs used to compare historical situations and predict pattern completion. It does not change the candle period, prediction horizon, BUY/HODL/SELL label definition, or select a different training algorithm. Pass one name, for example `--schema=technical`; the notation `core|technical|full` means alternatives, not a literal argument.

| Schema | Base features | Includes | When to use it |
| --- | --- | --- | --- |
| `core` (default) | 15 | Short-window trend, returns, momentum, volatility, volume activity and candle shape. | Start here, especially with recent history or incomplete CoinGecko coverage. |
| `technical` | 18 | Everything in `core`, plus 24-hour, 7-day and 30-day price returns. | Evaluate longer-term price context when those exact historical anchors are available. |
| `full` | 28 | Everything in `technical`, plus all 10 CoinGecko context features. | Evaluate market-wide and asset context when complete historical context is available for the chosen market. |

These counts describe the selected M2 inputs. Pattern length/stage/progress/similarity and any validated pattern-completion probabilities added by M4 are separate from these counts; choosing `full` does not enable pattern prediction by itself.

#### Core: the 15 shared technical features

| Group | Exact feature keys | Meaning |
| --- | --- | --- |
| Trend | `trend.ema_3_12`, `trend.direction` | Normalized difference between EMA 3 and EMA 12, and their relative direction. |
| Recent returns | `return.4`, `return.12` | Normalized close-price changes over 4 and 12 candles. |
| Momentum | `momentum.rsi_3`, `momentum.rsi_14`, `momentum.stoch_rsi_14`, `momentum.cci_20` | Normalized RSI, stochastic RSI and CCI measurements. |
| Volatility | `volatility.atrp_3`, `volatility.atrp_14` | Normalized average true range as a percentage of price. |
| Volume | `volume.activity_20` | Normalized volume activity relative to a 20-candle baseline. |
| Candle shape | `candle.body`, `candle.upper_wick`, `candle.lower_wick`, `candle.direction` | Relative body/wick sizes and bullish, bearish or flat direction. |

The numeric windows above count candles in the supplied period: `return.12` spans 12 minutes with `1m` candles and 12 hours with `1h` candles. The current M2 implementation needs at least 28 consecutive closed candles to finish the longest core warm-up. That only makes a feature vector eligible; training still needs enough subsequent candles to mature labels and enough eligible examples for its chronological training and validation blocks.

#### Technical: core plus three elapsed-time returns

`technical` adds `return.24h`, `return.7d` and `return.30d`. These are normalized close-price changes over actual elapsed time, regardless of the selected candle period. They do not derive another timeframe or train a second model.

All three values must exist for every included row. M2 looks for a close at the exact timestamp 24 hours, 7 days and 30 days before that candle, within the same uninterrupted history segment. A gap resets the segment, and a period whose timestamps cannot align with an anchor cannot provide that return. Consequently, `technical` generally needs at least 30 days of continuous history before its first eligible row, followed by enough eligible rows and mature outcomes for training. Merely having 30 days of candles does not guarantee a trainable model.

#### Full: technical plus ten CoinGecko context features

| Exact feature key | Meaning |
| --- | --- |
| `context.global_regime` | Normalized 24-hour change in total crypto market capitalization. |
| `context.btc_dominance` | Bitcoin's share of total crypto market capitalization. |
| `context.btc_dominance_change` | Normalized change in Bitcoin dominance recorded in the context snapshot. |
| `context.activity` | Normalized asset trading volume relative to its market capitalization. |
| `context.activity_deviation` | Normalized deviation of that activity from its recorded baseline. |
| `context.category_momentum` | Normalized momentum of the asset's configured category. |
| `context.price_deviation` | Normalized difference between the exchange close and CoinGecko's reference price in the matching quote currency. |
| `context.market_cap_share` | Asset market capitalization divided by global market capitalization. |
| `context.circulating_fraction` | Circulating supply divided by maximum supply. |
| `context.volume_share` | Asset volume divided by global volume; an activity/liquidity proxy. |

`full` requires a valid CoinGecko market mapping and context snapshots that were already observed and still fresh at each candle's decision time, including usable category data. Enabling CoinGecko today does not recreate context observations for past candles. Some assets lack a maximum supply or category data, so collecting more candles alone may never make all 10 context values available. The training command reads stored features; it does not fetch missing context or recalculate indicators.

#### Missing values and choosing between schemas

If any selected feature is missing, the dataset excludes that candle; it does not fill the value with zero or fall back to a smaller schema. Missing CoinGecko values do not exclude a row from `core` or `technical`, because neither selects them. A build with no eligible labelled rows fails; a build with too little validation evidence can finish as **abstaining**. During inference, a missing selected value produces an abstention with reason `missing_selected_features`.

Start with `core`. Consider `technical` or `full` only after checking their historical coverage, then compare the validation reports and usable row counts. More inputs do not guarantee better predictions, and results based on different eligible periods are not a like-for-like comparison. The model report and frozen dataset manifest record the selected schema and feature keys.

Choose one of these examples, replacing the market and period with your own:

```bash
# Default: 15 technical features.
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m --schema=core

# Add the three elapsed-time returns; their historical anchors must exist.
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m --schema=technical

# Also require all 10 historical CoinGecko context values.
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m --schema=full
```

The option applies to this manual build. Weekly queued training reads `schema` from `config/intelligence.php`, currently `core`; a manual `--schema=full` does not change that setting. With `--dataset=DATASET_UUID`, the dataset's recorded keys determine the inputs: omit `--schema`, because a frozen dataset cannot be changed by selecting another schema. The M3 `build-dataset` command uses the same feature selections but creates fee-aware labels, which M4 rejects.

## trademinator:signal

Description: Explain the latest closed-candle KNN signal and calibrated pattern probabilities

Signature: `trademinator:signal {exchange} {symbol} {period}`

All three arguments are required and preserve symbol/period case. Reads the current model and latest closed M2 features for that exact series. Requires migrations and, for a directional result, a validated non-stale model plus a later closed feature. Outputs JSON with `action` (`buy`, `hodl`, `sell`), confidence, evidence counts, similarity, votes, reason, decision time, model ID and emerging patterns. Missing/weak/stale evidence is a successful zero-confidence HODL result; corrupt artifacts or invalid periods exit nonzero. Read-only: no training, orders, queue dispatch or writes. No scheduled entry; CLI and the Intelligence page request inference on demand.

```bash
php artisan trademinator:signal bitso ADA/USD 1m
```

## trademinator:model-info

Description: Verify an intelligence artifact and show its schema, cutoffs and validation report

Signature: `trademinator:model-info {model}`

`model` is a model UUID, not a path. Requires the database record and private artifact. Verifies the SHA-256 digest and supported format, then prints the saved report without serializing the training matrix or estimator internals. Read-only; no options or schedule. Unknown IDs, unsafe paths and damaged/missing artifacts fail nonzero.

```bash
php artisan trademinator:model-info MODEL_UUID
```

## trademinator:dispatch-market-intelligence

Description: Queue weekly KNN and pattern training once per subscribed market and selected period

Signature: `trademinator:dispatch-market-intelligence`

No arguments/options. Queues one `TrainMarketIntelligence` job per shared market feed with a selected period and active subscriptions, regardless of subscriber count. Runs Mondays at 04:00 in the application timezone via `onOneServer()` and overlap protection. `INTELLIGENCE_ENABLED=false` returns successfully without dispatching. Requires a persistent queue backend; `sync`/`null` fail. Jobs go to `INTELLIGENCE_QUEUE` (default `intelligence`) and use shared unique locks, a per-market build lock and a durable unique weekly generation key. Successful abstaining builds also complete the week's generation. Queue retries or duplicate delivery cannot create another model for that completed generation, even if its completion cache entry was lost. Failure before publication remains retryable.

Prints the number of eligible dispatch attempts (a unique-job lock may suppress a duplicate). Side effects are queue records and locks; workers later create snapshots/models. It does not refresh exchange data/features itself. Prerequisites and cron-only queue workers are in [CRONTABS.md](CRONTABS.md). New subscriptions may be trained immediately by a manual dispatch; a completed weekly generation is reused. Use `knn-build` for an explicit rebuild during the same week.

```bash
php artisan trademinator:dispatch-market-intelligence
```

## trademinator:derive-timeframe

Description: Derive complete larger candles from the selected base period and reuse the M2 feature pipeline

Signature: `trademinator:derive-timeframe {exchange} {symbol} {base} {period} {--from=} {--as-of=}`

Arguments identify exchange, symbol, the feed's selected reliable base period, and a larger supported period. The target must be an exact fixed-duration multiple using minutes, hours or days. Calendar months/years and weeks are rejected rather than approximated. Requires a configured shared feed whose selected period equals `base`, stored base candles, migrated tables, shared cache and writable storage. `--from` defaults to a bounded recent range (3,000 plus 60 target candles); `--as-of` defaults to now and is capped at now. Both use the explicit UTC/millisecond syntax above. The starting bucket is rounded forward to a complete UTC boundary.

Generates only contiguous complete buckets whose final base candle is closed, using exact decimal volume sums. It records `derived_from`/derivation version in candle payloads, replaces derived buckets in the requested interval, removes previously derived buckets now known to contain gaps, invalidates affected target features and rebuilds them with the **same** FeatureBuilder/FeatureEngine/traits used by M2. It refuses to overwrite any existing target-series exchange candle or a different derivation. Do not run exchange fetching into a series reserved for derivation. Derived history can be recreated from retained base candles. No base candles are changed. Invalid input and size/time limits fail; no independent technical feature system is created.

Prints JSON with candle/feature counts, source/target periods and range. On demand only: no added fetch stream, schedule or permanent worker. Train and inspect timeframe-specific intelligence with the ordinary M4 commands after derivation; automatic weekly dispatch continues to use the feed's selected base period. Derived models remain separate per timeframe and are not silently blended into a single recommendation.

```bash
php artisan trademinator:derive-timeframe kraken BTC/USD 1m 5m --from=2026-09-20
php artisan trademinator:knn-build kraken BTC/USD 5m
php artisan trademinator:signal kraken BTC/USD 5m
```
