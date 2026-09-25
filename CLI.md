# Trademinator CLI reference

This is the canonical reference for every registered `trademinator:*` Artisan command. Update it in the same change whenever a command is added, renamed, removed, or its behavior, arguments, options, defaults, or side effects change. `AGENTS.md` records this requirement; `TrademinatorCliDocumentationTest` checks command coverage, lowercase names, signatures, and descriptions.

Run commands from the Laravel project root using the same PHP version and configuration as the application:

```bash
php artisan list trademinator
php artisan help trademinator:sync-ohlcv
```

Names use lowercase kebab-case. Argument values retain their required case: `BTC/USD` is an exchange symbol, `1m` is one minute, and `1M` is one month. Never lowercase symbol or timeframe values automatically. Date strings use PHP parsing; quote dates containing spaces and specify a timezone when needed. Persisted market timestamps are Unix milliseconds.

In signatures below, `{name}` is required, `{name?}` is optional, `{--flag}` is boolean, and `{--option=value}` supplies a default. These are Laravel signature declarations, not literal shell braces. Commands also support Laravel's standard help, verbosity, environment, and non-interactive options; see `--help` for the runtime's complete list.

## Command index

- [`trademinator:build-features`](#trademinatorbuild-features) — Replay stored completed candles into versioned, causal M2 features
- [`trademinator:collect-market-context`](#trademinatorcollect-market-context) — Collect timestamped CoinGecko context for explicitly mapped markets
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

Description: Collect timestamped CoinGecko context for explicitly mapped markets

Signature: `trademinator:collect-market-context`

Collect CoinGecko snapshots for the explicit market mappings in `config/features.php`. No command-specific arguments or options. Requires `COINGECKO_ENABLED=true`, an API key, and exact coin-ID/quote-currency mappings. Otherwise, disabled collection or no configured mappings stores zero snapshots. Uses a shared lock, batches coin requests, and avoids duplicate observations in the same UTC hour. Provider failures fail the command; they do not become fabricated values. Scheduled hourly.

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

`--tick-size` has no default and is required when subscribing, including reactivation. Supply the positive minimum price increment from the exchange's market metadata. Conflicting tick sizes on the same shared market are rejected. Unsubscribing makes the user's subscription inactive; it does not delete historical candles. The list includes active and inactive subscriptions. Billing remains separate.

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

Schedules are defined in `routes/console.php`. All use shared-cache scheduler locks. Configure a shared atomic-lock-capable cache and a persistent queue across nodes. Run Laravel's scheduler each minute and keep a worker running:

```cron
* * * * * cd /path/to/trader-server && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

```bash
php artisan queue:work --queue=default --timeout=600 --tries=5
php artisan queue:failed
```

Set the queue connection's `retry_after` to at least 720 seconds. After deploying code or configuration changes, clear/rebuild configuration as appropriate and restart long-running workers:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

## Where the KNN inputs are prepared

- `app/Domain/Features/FeatureEngine.php`: technical indicators and normalized technical features; `KEYS` defines their stable order.
- `app/Domain/Features/ContextFeatures.php`: normalized CoinGecko features and their ordered `KEYS`.
- **`app/Domain/Features/FeatureBuilder.php`**: combines those feature sets, creates the ordered `keys` and numeric `vector`, records missing values/readiness, and persists each row in `market_features`.
- `app/Models/MarketFeature.php`: reads saved rows with a decoded JSON payload.
- `app/Models/Knowledge.php`: existing knowledge-record model; it does not build a training matrix.

M2 creates one vector per completed candle. There is no full labelled matrix builder or KNN training call yet: M3 adds dataset assembly/backtesting, and M4 adds KNN. `rubix/ml` is installed, but installation alone does not mean training is implemented. Matrix assembly must keep identical feature order/version across rows and explicitly handle missing values before training. See [M2's data contract](M2-README.md#data-contract).
