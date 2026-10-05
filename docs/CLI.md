# Trademinator CLI reference

This is the canonical reference for every registered `trademinator:*` Artisan command. Update it in the same change whenever a command is added, renamed, removed, or its behavior, arguments, options, defaults, or side effects change. `AGENTS.md` records this requirement; `TrademinatorCliDocumentationTest` checks command coverage, lowercase names, signatures, and descriptions.

Run commands from the Laravel project root using the same PHP version and configuration as the application:

```bash
php artisan list trademinator
php artisan help trademinator:sync-ohlcv
```

If the exchange selector is empty after data loss, use the read-only `trademinator:exchange list` to inspect configured entries, then `trademinator:exchange add` for missing exchanges. The revised `php artisan db:seed --class=ExchangeSeeder --force` can fill the full installed CCXT catalogue while preserving existing records. See [M3 R1 recovery and test isolation](M3-R1-RECOVERY.md) before running tests from older packages.

For pair-loading errors, CCXT memory use and Alpaca credentials, see [M3 R2 markets repair](M3-R2-MARKETS.md). R3 extends that tool with source-bound access reviews and daily refresh. See [R3 access registry](M3-R3-ACCESS.md) and the [complete classification matrix](CCXT-OHLCV-CLASSIFICATION.md).

## Owner administration, syslog and local GeoIP

This update starts from GitHub `main` commit `f09e402f9977453065c9215852ba5a38661d9325` (M4.1). It adds server administration and operational logging; it does not introduce Client trading or change model decisions.

Preserve the production `.env`, `APP_KEY`, databases, private model/research artifacts and writable storage. Install the updated source and compiled assets, then run:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

The two new migrations add account suspension/activity timestamps and access-statistics tables. Do not use `migrate:fresh`. No training rebuild is required specifically for this update. Refresh configuration after editing `.env`:

```dotenv
OWNER_UUID=your-existing-user-uuid
ACTION_SYSLOG_ENABLED=true
ACTION_SYSLOG_IDENT=trademinator
ACTION_SYSLOG_FACILITY=local0
ACCESS_STATISTICS_ENABLED=true
ACCESS_STATISTICS_RETENTION_DAYS=90
GEOIP_DATABASE_PATH=/usr/share/GeoIP/GeoLite2-City.mmdb
TRUSTED_PROXIES=
```

```bash
php artisan config:cache
php artisan view:cache
php artisan schedule:list
```

Allow already-running cron workers to finish and start with the new source/configuration; do not interrupt a model build. These settings apply to all application nodes.

### Choosing the owner

`OWNER_UUID` must be the `users.user_id` of an existing account, not an API key. To find it locally:

```bash
php artisan tinker --execute='dump(App\Models\User::where("email", "you@example.com")->value("user_id"));'
```

Sign in with that account and verify its email. The sidebar now exposes **Server administration**, at `/owner`. An empty, malformed or nonmatching UUID grants no privilege. A UUID is an account identifier, not a password or authentication bypass; normal sign-in remains mandatory. Owner configuration cannot be changed from request data, account profile fields or an administration form. Rebuild the configuration cache after changing the owner.

The owner area provides:

- Global user totals, search, account details, verification status, last sign-in and last activity; profile editing, suspension/restoration and API-key revocation.
- Global subscriptions, active users/shared markets by exchange, subscriber lists and collector/backfill status per market.
- Current and historical model reports, model/dataset UUIDs, knowledge rows, selected K, reasons for abstention, chronological/holdout validation, pattern and lead/lag reports. Viewing reports does not deserialize training artifacts or run training.
- Daily application requests, authenticated requests, errors, response times, route totals, countries and cities, and approximate daily IP-visitor counts.
- Recent successful collection/model times, overdue feeds and the default database queue backlog/failure identifiers. Alternative queue drivers require their own queue monitoring.

Suspension immediately marks active subscriptions inactive, revokes the API key and remember-me token, and blocks new logins. Existing sessions are rejected on their next application request. Shared feeds remain active for other subscribed users. Restoring access does not reactivate subscriptions or recreate a revoked key. The configured owner cannot suspend themself through administration. Changing another user's email resets verification; the account must verify the new address through the existing workflow. Destructive user deletion and impersonation are deliberately not part of these management controls.

Reports begin accumulating after deployment. Last sign-in/activity timestamps and geographic request counts cannot be recovered retroactively.

### Syslog and trace IDs

The isolated action logger writes one JSON line per event directly to the local syslog socket with identifier `trademinator` by default. It does not forward the general Laravel log into syslog. Commands and job attempts have separate started/completed events so an unfinished operation is visible. HTTP requests have a completion event with the final response status; responses expose `X-Trademinator-Trace`. Jobs carry their originating trace as `parent_trace_id`, and each attempt gets its own trace. A completed worker invocation alone does not prove its market is healthy: inspect `feed.updated` status, `history.failed`, model status/reason, and numeric row counts.

Coverage includes HTTP requests (including rejected requests), authentication events, all normally bootstrapped Artisan command executions, queued/dispatched jobs and attempts/timeouts, committed user/exchange/market/subscription/preference/mapping model changes, public OHLCV fetches, candle synchronization, historical-backfill transitions, context collection, feature generation, dataset/model publication and intelligence decisions. Business-record events run after commit; batch operations have their own summaries. Utility scripts that do not boot Laravel and process failures before application boot remain the responsibility of OS/PHP logs.

Fields are explicitly restricted to fixed event/route/command/job labels, safe identifiers, public market names, counts, timings and outcomes. Error records contain the exception class and an application source filename/line, never the exception message, stack arguments, SQL bindings, request body, raw URL, query string, email, IP address, authentication token or job payload. Normal Laravel diagnostic files retain their existing behavior; do not add them to the action channel. The dedicated logger cannot inherit Laravel's shared log context.

```bash
journalctl -t trademinator --since "1 hour ago" -o cat
journalctl -t trademinator -f -o cat
journalctl -t trademinator -o cat | jq 'select(.trace_id == "trace-uuid" or .parent_trace_id == "trace-uuid")'
journalctl -t trademinator -o cat | jq 'select(.outcome == "failed" or .outcome == "error" or .outcome == "timeout")'
```

The host must have a working syslog/journald service, an accessible syslog socket for both CLI PHP and the web process, and retention/rate limits appropriate to its traffic. A chroot/container may need the socket exposed by its operator. Depending on the distribution, rsyslog may also write these events to `/var/log/syslog` or `/var/log/messages`. Running `php artisan list trademinator` should generate command lifecycle entries; verify them from your host. If the logger throws a transport error, the safe JSON record is sent to PHP's error log with `syslog_transport_failed`. Syslog itself may drop/rate-limit records without acknowledging delivery. These are operational diagnostics, not independently tamper-proof evidence.

### Local GeoIP database

`maxmind-db/reader` reads the configured **City MMDB file locally** for IPv4 and IPv6; it performs no remote geolocation request. Obtain a GeoLite2 City or GeoIP2 City database through your own MaxMind account/license and set `GEOIP_DATABASE_PATH` to its readable absolute path. Keep it outside the public web directory. The licensed production database is not included in this source package.

Use MaxMind's `geoipupdate` utility to keep the file fresh if it is installed on your server. Its `/etc/GeoIP.conf` contains `AccountID`, `LicenseKey`, `EditionIDs GeoLite2-City` (or your licensed City edition), and `DatabaseDirectory /usr/share/GeoIP`. Keep that configuration readable only by the account running updates. Neither MaxMind credentials nor download links belong in application logs. See the [official database-update instructions](https://dev.maxmind.com/geoip/updating-databases/) and [City database documentation](https://dev.maxmind.com/geoip/docs/databases/city-and-country/).

The dashboard shows database availability and its build date. Missing, unreadable, incompatible or corrupt files, unmapped addresses and private/reserved IPs yield **Unknown**; total request counting continues. It never infers city from a submitted header. City locations are estimates of IP networks and may reflect a VPN/proxy exit or mobile carrier rather than the visitor's residence.

When behind HAProxy or another proxy, list only its actual IPs/CIDRs in `TRUSTED_PROXIES`, comma-separated, and configure it to set a trustworthy `X-Forwarded-For` chain. Leave this empty when directly exposed. Never use `*` for a public server. Only the forwarding chain from trusted proxies influences the client IP; a direct request's forged forwarding/geography headers are ignored.

Access reports keep aggregates and daily HMAC IP fingerprints, never raw IPs, user-agent strings or request values. Hashes rotate by UTC date and are keyed by `APP_KEY`. “Daily IP visitors, summed” counts an IP once per UTC day; it is not a count of unique people across the whole reporting period. NAT/VPN use affects it. Health checks and static assets are excluded, while bots hitting application routes are included. Retention applies to aggregates and fingerprints; it does not alter OS log retention or Laravel's normal session storage. The scheduler prunes at 02:40 daily; see [CRONTABS.md](CRONTABS.md).

The tiny `tests/Fixtures/geoip/GeoIP2-City-Test.mmdb` file is a test-only fixture from [MaxMind-DB](https://github.com/maxmind/MaxMind-DB), with its upstream MIT/Apache licenses. It is never a production database or a fallback.

## trademinator:prune-access-statistics

Signature: `trademinator:prune-access-statistics`

Description: Delete access statistics older than the configured retention period

Arguments/options: none beyond standard Artisan options. `ACCESS_STATISTICS_RETENTION_DAYS` defaults to 90, with a minimum of 1. Keeps today and the preceding N−1 UTC dates. Deletes expired request aggregates and daily visitor hashes in bounded batches; it does not delete users, subscriptions, market data, models or system logs. Requires the access-statistics migrations and database write access. Returns 0 on completion; database errors fail the command. Runs daily at 02:40 in the application timezone, with shared scheduler locking. No queue worker or additional daemon is needed.

```bash
php artisan trademinator:prune-access-statistics
```

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

- [`trademinator:dispatch-market-signals`](#trademinatordispatch-market-signals) — Record current shared-market observations
- [`trademinator:refresh-market-discovery`](#trademinatorrefresh-market-discovery) — Refresh optional dashboard market context and discovery

- [`trademinator:prune-access-statistics`](#trademinatorprune-access-statistics) — Apply access-report retention

- [`trademinator:backfill-ohlcv`](#trademinatorbackfill-ohlcv) — Progressively collect older candles for shared subscribed markets

- [`trademinator:dispatch-lead-lag`](#trademinatordispatch-lead-lag) — Queue daily cross-exchange evidence and downstream model reevaluation
- [`trademinator:knn-build`](#trademinatorknn-build) — Build and validate M4 intelligence
- [`trademinator:signal`](#trademinatorsignal) — Explain the latest closed-candle signal
- [`trademinator:model-info`](#trademinatormodel-info) — Verify and inspect a model
- [`trademinator:analyze-validation-gates`](#trademinatoranalyze-validation-gates) — Calibrate Server-only model readiness thresholds
- [`trademinator:dispatch-market-intelligence`](#trademinatordispatch-market-intelligence) — Queue weekly shared-market training
- [`trademinator:derive-timeframe`](#trademinatorderive-timeframe) — Derive larger closed candles using the M2 pipeline

- [`trademinator:refresh-exchanges`](#trademinatorrefresh-exchanges) — Refresh installed CCXT access classifications and missing database entries

- [`trademinator:backtest`](#trademinatorbacktest) — Run purged walk-forward baseline evaluation on a frozen M3 dataset
- [`trademinator:build-dataset`](#trademinatorbuild-dataset) — Freeze M2 features and fee-aware future labels into an immutable M3 dataset
- [`trademinator:dataset-info`](#trademinatordataset-info) — Verify an M3 dataset checksum and display its frozen manifest

- [`trademinator:build-features`](#trademinatorbuild-features) — Replay stored completed candles into versioned, causal M2 features
- [`trademinator:collect-market-context`](#trademinatorcollect-market-context) — Collect timestamped CoinGecko context for subscribed markets
- [`trademinator:fetch-market-context`](#trademinatorfetch-market-context) — Fetch one mapped coin/quote immediately without a queue
- [`trademinator:collect-market-events`](#trademinatorcollect-market-events) — Discover GDELT GKG fork candidates for owner review
- [`trademinator:create-indicators`](#trademinatorcreate-indicators) — Build M2 indicators and feature vectors from stored completed candles
- [`trademinator:dispatch-market-features`](#trademinatordispatch-market-features) — Queue M2 feature builds for subscribed markets with selected candle periods
- [`trademinator:dispatch-market-feeds`](#trademinatordispatch-market-feeds) — Queue due market feeds with at least one active subscription
- [`trademinator:exchange`](#trademinatorexchange) — Add, edit, list, or delete configured CCXT exchanges
- [`trademinator:fetch-ohlcv`](#trademinatorfetch-ohlcv) — Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame
- [`trademinator:mailgun-check`](#trademinatormailgun-check) — Check Mailgun settings and optionally send a test message
- [`trademinator:market-subscription`](#trademinatormarket-subscription) — Manage user subscriptions that drive shared market collection
- [`trademinator:select-candle-period`](#trademinatorselect-candle-period) — Select the shortest sufficiently informative candle period
- [`trademinator:evaluate-candle-period`](#trademinatorevaluate-candle-period) — Reevaluate shared automatic periods with fee-aware label density
- [`trademinator:sync-ohlcv`](#trademinatorsync-ohlcv) — Fetch and upsert exchange candles, optionally inspect and repair missing ranges

## trademinator:collect-market-events

Signature: `trademinator:collect-market-events {--force : Reprocess the latest GDELT GKG batch}`

Description: Discover GDELT GKG fork-related event candidates for owner review

No positional arguments. `--force` is a boolean flag, off by default. The command reads the configured GDELT `lastupdate.txt`, downloads and validates the latest GKG batch when its MD5 differs from the last successfully processed batch, and classifies candidate titles. This is the GKG discovery path, not a GDELT DOC search.

`--force` bypasses the unchanged-batch check and reprocesses the latest batch; it does not fetch older batches or disable source-URL deduplication. Candidate rows are inserted or refreshed by source hash, and existing owner decisions are preserved. The run uses active subscriptions to match base symbols and applies `GDELT_MINIMUM_CONFIDENCE` (default `0.45`). Machine confidence is a heuristic for owner review, not a trading instruction or a confirmed event.

Requires outbound access to the configured GDELT feed, writable temporary storage for archive processing, the market-event database migration, and a shared cache supporting atomic locks. `GDELT_ENABLED=false`, an unchanged batch without `--force`, or an already-running collection causes a successful no-op. Collection errors are reported as `GDELT event discovery failed:` and return a failure exit code. The command takes a shared 900-second lock and releases it after processing.

```bash
php artisan trademinator:collect-market-events
php artisan trademinator:collect-market-events --force
```

Scheduled every five minutes with single-server and overlap protection. Processing is performed by the command; it does not dispatch a separate queue job or alter candle indicators, feature calculations, training labels, or owner decisions.

## trademinator:evaluate-candle-period

Description: Evaluate and optionally update automatic candle periods for subscribed markets

Signature: `trademinator:evaluate-candle-period {--exchange=} {--pair=} {--dry-run} {--outdated-only}`

Evaluates shared subscribed markets from the shortest supported period upward. Each candidate must pass candle-data quality and historical-depth checks, then the finalized M4/M5 auto-label pipeline must produce at least the configured BUY and SELL share (1% each by default) of `BUY + SELL + HOLD`. The first pass uses the latest seven days. If that window does not qualify, Trademinator extends the same candidate another seven days backward and evaluates the full 14-day window before trying a larger period.

Missing candidate history is collected with the same resumable history actions used by `trademinator:backfill-ohlcv`; the current selected period remains active while that happens. A successful replacement is switched atomically and its history cursor is returned to normal selected-period backfill. Existing historical candles are retained.

With no filters, the command evaluates all active shared markets. `--exchange=` limits the run to one configured exchange class and `--pair=` limits it to an exact exchange symbol such as `BTC/USD`; the two filters may be combined. `--dry-run` is read-only: it never changes `selected_period` and never queues missing history. `--outdated-only` is used by the scheduler to process only feeds whose saved selection algorithm version is older than the configured version, respecting the reevaluation retry time and the configured per-run batch limit.

```bash
php artisan trademinator:evaluate-candle-period --dry-run
php artisan trademinator:evaluate-candle-period --pair=BTC/USD --dry-run
php artisan trademinator:evaluate-candle-period --exchange=cryptocom
php artisan trademinator:evaluate-candle-period --exchange=cryptocom --pair=BTC/USD
```

The scheduler runs `--outdated-only` every 15 minutes. Deploying a higher `CANDLE_PERIOD_SELECTION_VERSION` makes existing feeds eligible automatically; users do not resubscribe. If reevaluation cannot qualify a replacement, the old selected period continues to collect normally and the evaluation is retried later.

## trademinator:candle-gaps

Description: Scan and report missing closed candles for active subscribed market feeds with suggested fixes

Signature: `trademinator:candle-gaps {--exchange=} {--symbol=} {--period=} {--no-scan}`

Finds missing closed candles across active subscribed market feeds by recursively bisecting time-aligned ranges. Each database range query returns only its count and first/last timestamps using the existing ticker index. Complete halves are skipped; empty halves become missing ranges immediately; incomplete halves are split again. The expected count comes from the time boundaries, not the number of stored rows. Off-grid timestamps cannot replace expected candles, and calendar periods use actual calendar boundaries.

Reports repair status, missing-candle counts, affected ranges, and copyable `trademinator:sync-ohlcv` commands. Adjacent or overlapping gap pages are merged into one command per consecutive missing range; separate holes are never combined into a broad historical download. For a single missing candle, the command starts one candle earlier and ends one candle later, including both neighbours. Longer missing ranges use their first and last missing candle start times.

The scan updates the existing gap-repair records; no new table or migration is required. It does not contact exchanges or queue repairs. It begins at the first stored candle and includes trailing closed gaps, excluding the open candle. Use `--no-scan` to report only already recorded gap state. Verified archived history is merged and checked through the canonical history reader once per scan, keeping a compact in-memory timestamp index for recursive probes; archives are not reopened at every split.

```bash
php artisan trademinator:candle-gaps
php artisan trademinator:candle-gaps --exchange=kraken
php artisan trademinator:candle-gaps --exchange=kraken --symbol=BTC/USD --period=5m
php artisan trademinator:candle-gaps --no-scan
```

## trademinator:backfill-ohlcv

Description: Queue resumable older OHLCV history for subscribed markets, or inspect and resume paused backfills

Signature: `trademinator:backfill-ohlcv {--exchange=} {--symbol=} {--period=} {--status} {--resume}`

Scheduled every minute. Queues at most 100 due shared markets per invocation on the `history` queue (`HISTORY_BACKFILL_QUEUE`). Requires the history migration, a persistent database/Redis queue, the same shared atomic-lock-capable cache on every scheduler/worker node, an active subscription, a selected candle period and existing closed candles from the normal collector. The worker preserves the exchange access review and credential checks. No user argument is needed: subscribers share one history cursor per market and period. The command also recovers pending feature/KNN rebuilds on the existing intelligence queue (at most 100 rebuild steps per invocation). Before dispatching older-history work, the command also scans selected-period history for holes between the earliest stored candle and the latest fully closed interval, persists bounded ranges in `candle_gap_repairs`, and queues due repairs on the same history queue. The currently open candle is never considered missing. Scheduled scans are throttled per market (six hours by default) and scan at most 10 feeds per invocation; supplying an exchange, symbol or period filter forces an immediate scan of the matching feed(s).

Options:

| Option | Default | Meaning |
| --- | --- | --- |
| `--exchange=` | All | Filter by configured CCXT exchange class, such as `bitso`. |
| `--symbol=` | All | Filter by the exact exchange symbol, such as `ADA/USD`. |
| `--period=` | All selected periods | Filter by the feed's selected period; values are case-sensitive (`1m` and `1M` differ). |
| `--status` | Off | Read-only JSON of saved progress, reasons, errors and retry times. Does not initialize or queue work; can be used while collection is disabled. |
| `--resume` | Off | Clear a paused state and queue another attempt. Requires all three filters and cannot accompany `--status`. Preserves candles and cursor. After an error it retries the failed page; after empty windows it searches further back. |

With no options, discover and queue due active subscriptions. `HISTORY_BACKFILL_ENABLED=false` disables dispatch and makes already queued work idle. Unsubscribed markets and outdated selected periods are skipped; changing a period creates an independent cursor. Resuming an old period requires selecting it on the feed again. An unexpired database lease is never reset by `--resume`.

Start immediately and inspect progress:

```bash
php artisan trademinator:backfill-ohlcv
php artisan trademinator:backfill-ohlcv --status
php artisan trademinator:backfill-ohlcv --status --exchange=bitso --symbol=ADA/USD
php artisan trademinator:backfill-ohlcv --resume --exchange=bitso --symbol=ADA/USD --period=1h
```

Only use the resume example with the market's actual selected period. Install the cron worker from [CRONTABS.md](CRONTABS.md#historical-ohlcv-backfill-worker); queuing work alone does not fetch history.

The first backward window ends at the earliest stored candle. Each logical window spans **24 hours or one selected candle, whichever is longer**. Calendar months/years use actual calendar boundaries. Within each window, pages run chronologically, each requesting at most 90 candles; a job performs at most five OHLCV calls and starts no new call after its 45-second budget. Each HTTP request has a 15-second timeout; the worker job has a 120-second hard timeout. A large window resumes over several jobs, with at least 60 seconds between completed passes. A short response resumes after its last accepted candle. A full window is completed before the cursor moves to the previous window.

The worker validates/normalizes through the existing ticker pipeline, accepts only closed candles inside the requested page, and atomically commits each page's upserts and checkpoint. Retries preserve unique exchange/symbol/period/timestamp rows. It does not invent missing candles, mix exchanges/periods or modify the live collector's forward cursor. Gaps still restart feature warm-up. Backfill holds the same feature lock used by M2 replay and dataset snapshots, so older inserts cannot shift a feature replay midway through its chronological scan. Normal five-minute M2 feature builds also replay imported history.

History is **not universally available** through exchange OHLCV endpoints. `since`, retention and errors vary by exchange; some endpoints return only a recent batch even for old requests. CCXT documents missing no-trade intervals and exchange-specific history limits: [CCXT manual](https://docs.ccxt.com/docs/manual). Kraken's OHLC endpoint explicitly limits history to 720 recent entries: [Kraken reference](https://docs.kraken.com/api-reference/market-data/get-ohlc-data). Automatic period selection therefore requires both recent candle quality and a read-only depth probe. By default the probe must reach the larger of seven calendar days or the configured KNN train + test + feature-lookback + label-horizon candle window. `HISTORY_BACKFILL_MINIMUM_DAYS`, `HISTORY_BACKFILL_DEPTH_PROBE_CANDLES` and `HISTORY_BACKFILL_RESELECT_SHALLOW_PERIODS` control that behavior.

Saved status and stop policy:

| Status / reason | Meaning and action |
| --- | --- |
| `pending`, `queued`, `active` | Waiting, in progress or ready for another bounded pass. `before_ms` is the exclusive backward search boundary; `next_since_ms` is the page checkpoint inside the current window. |
| `waiting_for_live_history` | No closed source candle exists yet; the backfill waits for normal collection to seed history. |
| `retrying / request_failed` | Cursor remains at the failed page. Retry delay doubles from 60 seconds to a maximum of one hour. Timeouts, network errors and rate limits do not count as proof of a history boundary and continue to retry. |
| `paused / no_older_data` | Three completed consecutive windows yielded no acceptable older candles (empty or outside the requested range). Automatic attempts stop. This is a reversible no-progress policy, **not proof** of the listing date or oldest exchange record. Use `--resume` to probe further through a long quiet gap. |
| `paused / exchange_history_boundary` | On NDAX, consecutive older windows were empty/out-of-range while the same symbol/period still returned a known recent control candle. This confirms that the current period cannot be retrieved farther back through that endpoint. If the retained span is too shallow and automatic reselection is enabled, the feed returns to `pending` so the collector can test longer periods without inventing candles or mixing timeframes. |
| `paused / repeated_errors` | Five consecutive non-network failures at the same checkpoint. Inspect `last_error`; this can be an exchange's historical-date rejection or a request/configuration problem. Correct the issue or deliberately resume. |
| `paused / access_or_support` | Unsupported symbol/period, credentials/permission issue, or exchange access review needs attention. Correct it, then resume. |
| `idle / inactive_feed` | No active subscription, a changed period or backfilling disabled. Resume automatically when the same feed/period becomes eligible again. |
| `complete / unix_epoch` | The lower timestamp boundary has reached zero; no negative timestamps are queried. |

`oldest_candle_ms` tracks the earliest actual candle known to this backfill, separately from the search boundary, which can pass through empty intervals. `candles_received` counts accepted page rows, including any already present due to an independent manual import. `empty_windows`, `failures`, `last_error` and `next_attempt_at` explain stalls. Window timestamp fields are UTC Unix milliseconds; ordinary retry timestamps follow Laravel's stored timestamp convention. Configuration for page size, per-job request/time budgets and pause thresholds is in `config/history_backfill.php`.

Missing-candle repairs are separate from the backward-history cursor. A repair requests only the exact closed gap range (at most the configured page size), uses the same market/history-exchange/feature locks as historical backfill, and upserts through the existing ticker repository. Partial responses are split into the remaining missing ranges. A successful request that still returns no candle retries after one hour, then six hours, then daily; after `HISTORY_GAP_EMPTY_ATTEMPTS_BEFORE_UNAVAILABLE` successful empty attempts (default 5) the range is marked `unavailable`. Network/rate-limit/request failures use the separate error retry policy and do not count as evidence that the exchange omitted the candle. Isolated holes also use the reconstruction policy documented under `sync-ohlcv`: complete smaller candles, an inferred next-open fill after successful empty lower-timeframe requests, or parent reconciliation of sparse/empty intervals on any exchange. Provenance and causal availability distinguish the methods. Request failures never trigger inferred filling. Any repaired historical insert increments the existing history revision so M2/KNN rebuilding sees the correction. Gap scan cadence and dispatch limits are controlled by `HISTORY_GAP_SCAN_INTERVAL_MINUTES`, `HISTORY_GAP_SCAN_MARKETS_PER_RUN`, and `HISTORY_GAP_REPAIR_DISPATCH_LIMIT`.

After a **completed backward window imports at least one candle**, the worker automatically queues an ordered rebuild on `INTELLIGENCE_QUEUE` (default `intelligence`):

1. Rebuild M2 features from the enlarged closed-candle history.
2. Build and validate fresh KNN/pattern intelligence using `config('intelligence.schema')` (default `core`).

The two stages are separate jobs, each limited to 900 seconds; use the intelligence worker and `retry_after >= 1080` documented in [CRONTABS.md](CRONTABS.md#m4-intelligence-workers). Feature replay retains its 540-second cooperative limit. The trained generation uses the history revision, independently of the weekly generation key; this week's existing model cannot suppress the new build. Empty windows and failed imports do not trigger a new revision. Only one rebuild pipeline per market/period is queued or running. Several imports waiting for features are folded into one build; imports arriving after feature preparation stay pending for one follow-up pass. Duplicate/stale jobs cannot acknowledge a newer revision. Feature and model failures preserve imported candles and pending work, record `build_error`, and retry with exponential delay from 60 seconds to one hour. The minute scheduler recovers an expired lease or an interrupted dispatch. `INTELLIGENCE_ENABLED=false` or `HISTORY_BACKFILL_ENABLED=false` pauses automatic rebuilding without discarding pending revisions; inactive/changed feeds are deferred too.

`--status` also exposes `history_revision`, `trained_revision`, `build_stage` (`features` or `knn`), `build_revision`, `build_failures`, `build_error`, `build_next_attempt_at`, `model_id` and `last_trained_at`. Matching history/trained revisions mean every completed import has been included in a successful build. A successful build can still produce an abstaining model if validation fails; it does not force a BUY/SELL signal.

Manual rebuilding remains available for troubleshooting or a different schema:

```bash
php artisan trademinator:build-features bitso ADA/USD 1h
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1h --schema=core
```

Use the exact exchange/symbol/period from the feed. The weekly intelligence dispatcher retains its weekly generation key. Backfill-triggered rebuilds and direct `knn-build` create fresh models independently of that weekly generation. Backfilling supplies candles; it does not guarantee validation success or effective neighbours. `core` avoids the 24-hour/7-day/30-day return requirements; `technical` needs those historical spans. `full` also needs genuinely historical CoinGecko snapshots, which this command cannot reconstruct. Never apply today's context to historical candles. Training uses every eligible example within `INTELLIGENCE_MAX_MODEL_AGE_DAYS` of the build cutoff, preserving chronological validation and warm-up requirements. Very long retained history increases feature-replay and training cost; the existing time budgets remain in effect.

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

## trademinator:fetch-market-context

Description: Fetch fresh CoinGecko context immediately for one mapped coin/quote or exchange/pair

Signature: `trademinator:fetch-market-context {--coin=} {--vs-currency=} {--exchange=} {--symbol=}`

Runs the CoinGecko requests and saves the snapshot in the current CLI process. Nothing is queued. Supply exactly one complete selector: `--coin` (the CoinGecko ID, not ticker) plus `--vs-currency` (the exact quote), or `--exchange` (CCXT class) plus `--symbol` (BASE/QUOTE). All four options default to unset; incomplete or mixed selectors fail. The selected market or coin/quote must already have a resolved CoinGecko mapping; an active subscription is not required for this manual fetch. It does not resolve unrelated mappings or collect unrelated coins. Pair selection uses the mapped asset and exact quote, not an exchange listing on CoinGecko.

```bash
php artisan trademinator:fetch-market-context --exchange=bitso --symbol='ATOM/USD'
php artisan trademinator:fetch-market-context --coin=cosmos --vs-currency=usd
```

Every invocation requests fresh context even if a snapshot exists in the current UTC hour; no `--force` option is needed. Requires `COINGECKO_ENABLED=true`, `COINGECKO_API_KEY`, and the same cache lock used by `collect-market-context`. An occupied lock, invalid selection/configuration, provider failure, or absent/stale coin data returns failure. Global and category endpoints are shared dependencies of this one coin's context. Existing snapshots remain unchanged; new snapshots use receipt time and cannot fill historical context gaps. Repeated manual refreshes count at most once per completed UTC hour in the activity history, preserving its warm-up and the 24-hour dominance lookup.

Prints the selected coin/quote before fetching and the saved count after completion. A saved snapshot may still have null provider fields or context warm-up values. Maximum supply is not used by the current feature schema, so uncapped supply does not prevent full-context readiness. This command does not rebuild M2 features or train KNN. It has no schedule; the existing hourly `collect-market-context` schedule is unchanged. The existing collection command also runs directly when invoked from the CLI, but targets active subscriptions and skips already-collected hours.

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

Description: Queue M2 feature builds for subscribed markets whose current feature version is behind

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

Signature: `trademinator:exchange {action : add, edit, delete, or list} {class? : The CCXT exchange ID, such as kraken} {--name= : Exchange display name for add or edit} {--config= : CCXT settings as a JSON object} {--config-file= : Path to a file containing a CCXT JSON object} {--timezone= : Explicit IANA timezone for intelligence session context} {--search= : Filter the list by CCXT ID or name} {--force : Skip the deletion confirmation}`

Manage stored CCXT exchange configurations. `action` is required: `add`, `edit`, `delete`, or `list`. The positional `class` is the CCXT exchange ID; required for mutations, omitted for `list`.

| Option | Default | Meaning |
| --- | --- | --- |
| `--name` | CCXT ID when adding | Display name; 1–64 characters. |
| `--config` | `{}` when adding | CCXT settings as a JSON object. |
| `--config-file` | None | Read a JSON object from a readable file up to 64 KiB; mutually exclusive with `--config`. |
| `--timezone` | UTC / unknown for new exchanges | Operator-selected IANA timezone (for example `America/Mexico_City`); add/edit only. Stored separately from CCXT credentials. |
| `--search` | None | Filter the list by CCXT ID or display name. |
| `--force` | Off | Skip deletion confirmation. |

```bash
php artisan trademinator:exchange list --search=kraken
php artisan trademinator:exchange add kraken --name=Kraken
php artisan trademinator:exchange edit kraken --config-file=/secure/kraken.json
php artisan trademinator:exchange delete kraken
```

Edits require at least one of name/config/config-file/timezone. The list includes timezone and source. An explicit timezone is retained across automatic refreshes. Active subscriptions block deletion. Deletion removes exchange configuration, markets, inactive subscriptions, and feed records; historical candles remain. Prefer a protected config file for API secrets rather than putting them in shell history. See [exchange details](EXCHANGES-CLI.md).

## trademinator:fetch-ohlcv

Description: Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame

Signature: `trademinator:fetch-ohlcv {exchange} {symbol} {period} {from?} {to?} {--debug}`

Fetch, persist, and print OHLCV for a configured exchange. Required arguments: `exchange`, `symbol`, `period`. The optional positional `from` defaults to **yesterday** and `to` to **now**. `--debug` enables verbose CCXT output only when no credentials are in use; authenticated request details are never printed. This command calls the existing fetch repository, which saves candles before returning them; it is not a read-only preview.

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

`--tick-size` has no default and is required when subscribing, including reactivation. Supply the positive minimum price increment from the exchange's market metadata. Conflicting tick sizes on the same shared market are rejected. Unsubscribing makes the user's subscription inactive; it does not delete historical candles. The list includes active and inactive subscriptions. Billing remains separate. R3 requires a current reviewed public/authentication-required adapter when subscribing or reactivating; unknown or removed adapters are rejected before network access. Authentication-required adapters need credentials saved in Settings → Exchange keys, a shared owner key, or the legacy exchange configuration. The named subscription user’s personal credentials take priority. CLI subscriptions retain their existing market-type behavior; the web form is spot-only. Existing subscriptions are preserved if an adapter later becomes unknown, but shared collection pauses with a blocked status and rechecks in six hours. A fresh current metadata snapshot is required; use `trademinator:refresh-exchanges` after dependency changes.

```bash
php artisan trademinator:market-subscription subscribe user@example.com kraken BTC/USD --tick-size=0.01
php artisan trademinator:market-subscription list user@example.com
php artisan trademinator:market-subscription unsubscribe user@example.com kraken BTC/USD
```

## trademinator:select-candle-period

Description: Select the shortest sufficiently informative candle period

Signature: `trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--coverage=0.8} {--minimum=50} {--sample=250} {--from=7 days ago} {--to=now}`

Fetch candidate candle periods and select the shortest one meeting the quality, coverage, and completed-sample requirements. Each candidate fetch is restricted to the most recent `--sample` periods plus one boundary period inside the requested interval; it does not download the entire date range merely to discard older candles. Required: `exchange`, `symbol`. Prints the decision as JSON and stores a successful decision in `candle_period_selections`; fetched candles are also persisted. Returns failure when no candidate qualifies. A direct CLI decision does not update a shared feed's selected period; the M1 collector manages that field.

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
| `--repair-gaps` | Off | Retry missing candles and reconstruct isolated holes, including inferred next-open fills after empty lower requests. |
| `--queue` | Off | Queue sequential page jobs; requires a persistent queue. |
| `--page-size` | `90` | Integer from 10 to 100 passed as the CCXT request limit. |

```bash
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='7 days ago' --incremental --repair-gaps
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='90 days ago' --queue --page-size=90
```

Overlapping pages can count the same candle more than once in totals; unique database keys prevent duplicate rows. Recent fetched candles can still be open; M2 only emits features after candle completion. See [pagination details](SYNC-PAGINATION.md).

With `--repair-gaps`, an isolated hole must have both adjacent, completed exchange candles. The same four-step policy applies to every exchange using its supported historical OHLCV timeframes:

1. **Smaller candles:** after retrying the native timeframe, try up to three supported, fixed smaller timeframes, largest first, that divide the target interval exactly. For Bitso `15m`, these are `5m` then `1m`; an exchange advertising `3m` can use that too. Complete coverage supplies exact trade-price OHLC and decimal volume. Each source window is capped at 100 candles.
2. **Parent reconciliation:** when step 1 did not consist entirely of successful empty lower-timeframe responses, try the two nearest supported larger fixed timeframes that are exact multiples of the target, with at most 100 child intervals. For Bitso `15m`, these are `30m` then `1h`. The parent must be closed, and every other target-timeframe child must be present. Parent volume and OHLC must exactly match the known siblings plus any observed smaller candles inside the hole. A sparse smaller series is usable only after this reconciliation accounts for its activity. Trade counts and first/last trade times, when supplied, must also reconcile; malformed or incomplete supplied metadata cannot silently be ignored. Bitso's raw endpoint retains that additional evidence.
3. **Empty lower-timeframe fallback:** if at least one supported lower timeframe was queried and every checked lower timeframe returned zero candles inside the missing interval, skip parent requests and insert `open = high = low = close = next candle's open`, with `volume = 0`. Both immediate neighbours must be native, present and closed. Mark the result `method: next_open`, `verification: empty_lower_timeframes`, and `inferred: true`. This is an explicit filling policy, not proof of no trading. Timeouts, failed requests, unsupported lower timeframes, and partial nonempty lower series do not trigger it. The existing parent-verified `no_trades` path continues to use the previous close when its separate reconciliation succeeds. Exact decimal comparisons use BCMath without a rounding tolerance; generic CCXT evidence requests use string-number mode and restore the original mode afterwards.
4. **Provenance and training:** persist the source candles, verification method, evidence digest and availability time. A repair that depends on a parent becomes usable only when that parent closes. A `next_open` fill becomes usable only when the following candle closes; a feature decision at the missing candle’s own close cannot use that later price. The existing feature/dataset/KNN rebuild workflow and future-evidence safeguards apply to all exchanges.

Consecutive holes, reconstructed neighbours and partial nonempty evidence that cannot be reconciled remain unresolved. The empty-lower fallback returns before consulting parents, so missing or invalid parent trade metadata does not block it. Prices are never linearly interpolated. Native exchange candles recovered while checking a parent are used directly. Only the explicit empty-lower fallback permits an inferred fill; other recovery paths require the corresponding exchange evidence.

Reconstruction adds at most seven evidence requests per isolated hole, with a 45-second budget for starting requests and a 15-second request timeout. Manual pages use the same market, exchange, history and feature locks as queued repair work. A busy lock asks the operator to retry. The direct command stays synchronous; successful changes can dispatch the existing intelligence rebuild job on its configured queue.

JSON output includes `pages`, `fetched`, `repaired`, `reconstructed`, and `missing_ranges`. `fetched` counts returned native candles, including existing rows. With repairs enabled, `repaired` counts previously absent timestamps inserted in that page, including the initial native fetch; `reconstructed` is the subset rebuilt from evidence. The latter counters do not count existing neighbouring candles as repairs. `missing_ranges` is the sum of observed remaining interior ranges across overlapping pages. Run `candle-gaps` for the feed-wide status. Manual requests advance the existing gap retry records and resolve successful repairs; live queued leases are preserved. With repairs enabled, `reconstruction_details` includes the candle timestamp, final reason and per-timeframe checks for the first 100 attempts; `reconstruction_details_omitted` counts any remaining attempts. Examples of unresolved reasons are `parent_candle_unavailable`, `parent_siblings_incomplete`, `parent_volume_mismatch`, `parent_prices_mismatch` and `invalid_trade_metadata`. Both manual and queued repairs also log `candles.reconstruction_check` and `candles.reconstruction` with `candle_ms`. A zero reconstruction count therefore comes with an explanation. A successful inferred fill reports `reason: reconstructed_next_open`; subsequent runs do not insert it again, and a later native exchange candle can replace it.

Each reconstructed candle carries a `reconstruction` payload with its verification basis (`complete_lower_timeframe`, `parent_ohlcv`, `parent_ohlcv_and_trade_counts`, or `empty_lower_timeframes`), method (`lower_timeframe`, `no_trades`, or `next_open`), version, source timeframe/bounds, evidence and digest, reconstruction time, and evidence availability time. Derived larger candles retain provenance and exclude reconstructed zero-volume intervals from trade-price OHLC calculations. Provenance survives archival and is propagated into features, immutable dataset sources/manifests and `training_data.reconstruction` in model reports. Feature decisions and pattern/semantic labels cannot use evidence before its source candles close. Native candles supersede reconstructions, including archived reconstructions; other hot/cold conflicts remain errors. Both reconstruction and native replacement mark the affected history for the existing feature/dataset/KNN rebuild pipeline. Existing frozen datasets are preserved; a rebuild produces a new snapshot.

For example, rerun an isolated gap command already printed by `candle-gaps`:

```bash
php artisan trademinator:sync-ohlcv bitso 'ATOM/USD' 15m --from='2024-02-28T20:45:00Z' --to='2024-02-28T21:15:00Z' --repair-gaps
php artisan trademinator:candle-gaps --exchange=bitso --symbol='ATOM/USD' --period=15m
```

Queued repairs use the same reconstruction policy automatically. No additional command, migration, scheduler entry or queue is required. Automatic intelligence rebuilding requires an active feed, enabled history/intelligence processing and a persistent queue serviced as described in [CRONTABS.md](CRONTABS.md). Without those prerequisites, rebuild explicitly with `trademinator:build-features` followed by `trademinator:knn-build` for the affected market.

## Scheduler and workers

| Command | Schedule |
| --- | --- |
| `trademinator:dispatch-market-feeds` | Every minute |
| `trademinator:dispatch-market-features` | Every five minutes |
| `trademinator:collect-market-context` | Hourly |

Schedules are defined in `routes/console.php`. All use shared-cache scheduler locks. Configure a shared atomic-lock-capable cache and a persistent queue across nodes. Trademinator does not require a permanent worker daemon: configure the scheduler cron and the `queue:work --stop-when-empty` queue-drain cron documented in [CRONTABS.md](CRONTABS.md).

Set the queue connection's `retry_after` to at least 1080 seconds. After deploying code or configuration changes, clear/rebuild configuration as appropriate:

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
| `--schema` | `core` | `core`: 15 technical features without long elapsed returns; `technical`: all 18 technical features; `full`: technical plus all 9 CoinGecko context features; `custom`: exact keys from `--features`. See [Choosing a feature schema](#choosing-a-feature-schema) for the feature lists and history requirements shared with M4. |
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

The required `dataset` is the UUID printed by `build-dataset`, not a path. Requires both its database record and private snapshot directory. Reads and verifies the manifest, SHA-256 checksum, row count, chronological order and row contract, then prints the manifest as JSON. Read-only; unknown IDs and corrupt/missing files fail. Fee-aware M3 research datasets remain bounded by `research.max_rows`; current semantic M4 datasets have no row cap. No command-specific options or schedule.

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
- M4 semantic snapshots are explicitly cost-free public-market knowledge (`cost_model=none`). Their `gross_return`, `buy_price_return` and `sell_base_price_return` fields describe price movement only; they are not Client profitability estimates and contain no fees, spread or slippage. M3 fee-aware research datasets retain their separate `buy_net_return` and `sell_base_net_return` fields, and the M3 portfolio backtester rejects M4 semantic snapshots. Existing `m4-turning-points-v1` snapshots remain immutable but are rejected for new M4 training; rebuild them to obtain the v2 row contract. Existing trained models remain usable because the semantic action definition itself did not change.
- Existing M2 features are reused in their stored order. `trend.direction` and `candle.direction` map from -1/0/1 to 0/0.5/1 at the model boundary; other features remain within 0–1. M2 artifacts are unchanged. Core is the default schema; `full` includes available CoinGecko context and may drop many rows when context history is incomplete.
- KNN uses RMS distance on normalized vectors, a maximum distance of 0.25, inverse-distance weights, at least 3 effective neighbors and confidence at least 0.6. Effective count is `(sum(weights)^2)/sum(weights^2)`. Exact matches alone vote when present. Ties, distant evidence and insufficient effective support yield `hodl`, confidence 0 and a reason. UI displays HODL as HOLD. Confidence is agreement times similarity, **not** a calibrated profit probability.
- Tuning uses expanding chronological folds, starting with at least `intelligence.knn.min_train_size` mature training rows (default 250) and 100 test rows. Every earlier eligible training row remains available to later folds; 250 is a validation minimum, not a knowledge cap. Outcome endpoints must be strictly before a fold's first decision. `Kmax = min(floor(sqrt(min_train_size)), k_cap)`; default cap 65. Coarse candidates are refined near the best eligible value. Semantic directional precision ranks first, then confidence, coverage and stability; excessive top/bottom contradictions disqualify a candidate. Defaults require at least 50 validation rows, 5 directional predictions, 55% semantic precision, 1% directional coverage and at most 5% contradictions.
- The final 20% is reserved for a later evaluation block and never selects K. Its predictions search all eligible pre-holdout training rows, and it must also pass the evidence gates. The published knowledge then contains every eligible mature row in the build's age window, after required source checks and chronological helper-model exclusions. A rejected build publishes an abstaining head so an older model is not silently presented as newly validated. Historical model artifacts remain available by ID.
- `INTELLIGENCE_MAX_MODEL_AGE_DAYS` (default 14, positive integer days) governs both training history and model expiry. At cutoff `T`, decision timestamps must be at least `T - N days`, including that boundary, and their full label horizon must be closed by `T`. An earlier `--from` is clamped to this boundary; explicit frozen datasets are filtered again before training. Older candles may supply feature/label warm-up, but cannot become retained examples. Neither the former 3,000-row build cap nor the 250-example retention cap applies. The artifact records the window and excluded-row count. Each rebuild replaces the active model; between builds its knowledge stays frozen. A model expires once its cutoff is more than `N` days old. Changing the setting requires refreshing cached configuration and rebuilding to change existing knowledge. Models from the previous validation version must be rebuilt.
- Automatic training retains its 480-second deadline. Human Candle training has a separate 300-second allowance and a further 10-second publication reserve, with a 900-second job timeout, 1020-second training locks and queue reservations of at least 1080 seconds. A human-stage timeout skips its vote while allowing publication of a supported automatic model; source-integrity and unexpected failures still propagate. Structured `intelligence.human_training.*` action/syslog events identify the stage and progress; see [worker deployment and logging](CRONTABS.md#m4-intelligence-workers). Longer windows require more memory and computation; adjust the window or coordinate application/worker time budgets as needed. Features older than 2 candle periods cannot issue a signal. Raw source/feature mismatches, changed feature versions, or corrupt artifacts fail closed.

### Pattern prediction and chronological stacking

The first catalogue contains bullish/bearish engulfing and gap-free morning/evening star variants appropriate to continuous crypto candles. An initial long candle has stored normalized body at least 0.5, opposing the predicted completion direction, with compatible stored trend direction. Stars add a small middle candle (body at most 0.3). Engulfing completes when the next candle reverses direction and covers the first body. A star completes when its third candle reverses direction and closes beyond the first body's midpoint. These definitions are versioned in `PatternCatalog`; unfinished legacy trait routines are not enabled.

Candidates contain type, total length, current stage, progress and similarity. Pattern vectors reuse the selected normalized M2 vector plus length/stage/progress/similarity. Only the target test examines future candle geometry; no second technical-indicator implementation is introduced. Both completions and failures are sampled, and a candidate's label becomes available only after its remaining candles close.

The first 40% of a knowledge snapshot supplies pattern samples. For each type, the earlier 60% trains Rubix ML Random Forest and weighted KNN. The next 20% is divided chronologically into calibration fitting and algorithm selection; boundaries purge outcomes reaching the next block. Brier score, log loss and calibration error on the selection block choose the algorithm. The final 20% evaluates that fixed choice, which must beat the prior completion-rate baseline. A failed final check does not switch to the other algorithm. Candidate final metrics remain visible for comparison but do not select the winner. At least 100 samples, 15 rows in each of the four blocks after purging, and both classes in training/calibration are required by default; the 100-sample count alone is therefore not sufficient. Random Forest is stochastic; persisted artifacts preserve the exact fitted model, but rebuilds can differ.

By default, validated completion probabilities can feed the final KNN alongside a presence flag. Only KNN rows strictly after the pattern model's **entire** evaluation horizon are eligible. This sacrifices history to prevent stacking leakage. No future outcome is fed back as a historical probability. An absent pattern uses probability 0.5 with presence 0; a pattern without a validated model has no probability. No eligible pattern models leaves the original KNN vector unchanged. Set `patterns.as_knn_features` false to expose probabilities separately. Rebuilding preserves the old artifact; it never mutates past snapshots.

Artifacts live under `storage/app/private/intelligence`, with independent database digests verified before decoding. Keep them private and share this directory and `storage/app/private/research` across worker/web nodes. Back up the artifacts and their database records together. There is no upload/import endpoint for arbitrary serialized models. `INTELLIGENCE_ENABLED=false` disables scheduled dispatch; explicit CLI analysis remains available. `INTELLIGENCE_QUEUE` defaults to `intelligence`. Other bounded defaults are in `config/intelligence.php` and apply to new builds; inference uses each model's recorded thresholds.

## trademinator:knn-build

Description: Build closed-candle semantic knowledge and validate KNN and pattern intelligence

Signature: `trademinator:knn-build {exchange} {symbol} {period} {--dataset=} {--schema=core} {--from=} {--to=} {--as-of=}`

Required arguments identify the exact exchange class, symbol and candle period. Requires migrated M2/M3/M4 tables, existing current-version M2 features, a lock-capable cache, and writable private research/model directories. Runs synchronously and offline; use the dedicated queue for scheduled work. Acquires shared per-market locks. Prints the model report as JSON, including model/dataset UUIDs, selected K or null, status, schema, training cutoff, fold reports, held-out metrics and pattern comparisons. Exit 0 includes a completed **abstaining** model; it does not assert a usable trading signal. Invalid data, lock conflicts, corrupt input, compute limits and publication failures exit nonzero.

| Option | Default | Meaning |
| --- | --- | --- |
| `--dataset` | New snapshot | Train a verified frozen M4 semantic dataset UUID for this exact market. Old M3 fee-aware labels are rejected. Cannot combine with date options or non-default schema. |
| `--schema` | `core` | Select the stored M2 inputs: `core` (15 technical features), `technical` (18), or `full` (18 technical + 9 CoinGecko context features). See [Choosing a feature schema](#choosing-a-feature-schema). Custom schemas can be consumed from an explicitly built semantic dataset through the domain service. |
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
| `full` | 27 | Everything in `technical`, plus all 9 CoinGecko context features. | Evaluate market-wide and asset context when complete historical context is available for the chosen market. |

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

#### Full: technical plus nine CoinGecko context features

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
| `context.volume_share` | Asset volume divided by global volume; an activity/liquidity proxy. |

`full` requires a valid CoinGecko market mapping and context snapshots that were already observed and still fresh at each candle's decision time, including usable category data. Enabling CoinGecko today does not recreate context observations for past candles. Missing category data or historical context can still prevent all 9 context values from being available. The training command reads stored features; it does not fetch missing context or recalculate indicators.

Feature version `m2-v6` removes `context.circulating_fraction` entirely. No current feature depends on maximum supply, and no total-supply replacement is added. Full-context readiness now counts 9 values. After upgrading, run `trademinator:build-features` and rebuild datasets/KNN models for the new version; old rows and models are preserved but are not compatible with the new version. Custom feature lists must also remove the retired key. See [M2's feature contract](M2-README.md#data-contract).

#### Missing values and choosing between schemas

If any selected feature is missing, the dataset excludes that candle; it does not fill the value with zero or fall back to a smaller schema. Missing CoinGecko values do not exclude a row from `core` or `technical`, because neither selects them. A build with no eligible labelled rows fails; a build with too little validation evidence can finish as **abstaining**. During inference, a missing selected value produces an abstention with reason `missing_selected_features`.

Start with `core`. Consider `technical` or `full` only after checking their historical coverage, then compare the validation reports and usable row counts. More inputs do not guarantee better predictions, and results based on different eligible periods are not a like-for-like comparison. The model report and frozen dataset manifest record the selected schema and feature keys.

Choose one of these examples, replacing the market and period with your own:

```bash
# Default: 15 technical features.
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m --schema=core

# Add the three elapsed-time returns; their historical anchors must exist.
php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m --schema=technical

# Also require all 9 historical CoinGecko context values.
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

## trademinator:analyze-validation-gates

Description: Analyze Server model holdouts to calibrate directional-count and semantic-precision readiness gates

Signature: `trademinator:analyze-validation-gates {--directional=5,20,30,50,75,100 : Comma-separated candidate minimum directional counts} {--precision=0.55,0.60,0.65 : Comma-separated candidate absolute semantic precision floors} {--baseline-lift=0.10 : Required Wilson lower-bound lift over the training prediction-mix baseline} {--wilson-floor=0.50 : Absolute Wilson 95% lower-bound floor} {--all-models : Include historical models instead of current heads only} {--limit=500 : Maximum models when --all-models is used} {--json : Print the complete report as JSON}`

Read-only Server calibration. It never retrains, republishes, changes model readiness or reads Client balances, account fee tiers, private execution state, realized P&L or other Client-only information. By default it analyzes one current head per market so repeated historical rebuilds do not overweight a market; `--all-models` includes historical model rows up to `--limit`.

For each model with a persisted final holdout, the command verifies the private model artifact and frozen source dataset, reconstructs the automatic pre-holdout training chronology from its pattern and lead/lag availability cutoffs (plus legacy stacked guidance cutoffs on older artifacts), and refuses that model if the reconstructed tuning-row count differs from the stored model report. The baseline uses only the final evaluation training window: BUY and SELL label rates are weighted by the model's actual holdout BUY/SELL prediction mix. No holdout labels enter the baseline.

The report shows directional sample count, exact directional correctness, semantic precision, the 95% Wilson lower confidence bound, prediction-mix baseline, directional-majority reference baseline, coverage and contradiction rate. Candidate gates preserve each model's existing minimum validation-row, coverage and contradiction requirements and additionally require:

`Wilson95 >= max(wilson_floor, training_prediction_mix_baseline + baseline_lift)`.

The default candidate grid is intentionally broad: directional minima `5,20,30,50,75,100` crossed with absolute semantic-precision floors `55%,60%,65%`. Use the distribution and pass counts to choose F1 readiness thresholds from actual Trademinator Server evidence rather than changing production settings first.

| Option | Default | Meaning |
| --- | --- | --- |
| `--directional` | `5,20,30,50,75,100` | Comma-separated positive candidate values for `min_directional_predictions`. |
| `--precision` | `0.55,0.60,0.65` | Comma-separated candidate absolute semantic-precision floors from 0 to 1. |
| `--baseline-lift` | `0.10` | Required Wilson lower-bound lift over the training-derived prediction-mix baseline. |
| `--wilson-floor` | `0.50` | Absolute 95% Wilson lower-bound floor. |
| `--all-models` | Off | Include historical model rows instead of current heads only. |
| `--limit` | `500` | Maximum historical models when `--all-models` is enabled; integer 1–5000. |
| `--json` | Off | Emit the complete machine-readable report instead of tables. |

```bash
php artisan trademinator:analyze-validation-gates
php artisan trademinator:analyze-validation-gates --directional=20,30,50 --precision=0.55,0.60,0.65
php artisan trademinator:analyze-validation-gates --all-models --limit=1000 --json
```

No scheduler, queue worker, exchange request or model-version change is added. Missing/corrupt model or dataset artifacts, models without a final holdout, and models whose final training chronology cannot be reproduced are listed as skipped instead of being assigned speculative statistics.

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

Arguments identify exchange, symbol, the feed's selected reliable base period, and a larger supported period. The target must be an exact fixed-duration multiple using minutes, hours or days. Calendar months/years and weeks are rejected rather than approximated. Requires a configured shared feed whose selected period equals `base`, stored base candles, migrated tables, shared cache and writable storage. `--from` defaults to `INTELLIGENCE_MAX_MODEL_AGE_DAYS` before the cutoff plus 60 earlier target candles for warm-up; `--as-of` defaults to now and is capped at now. Both use the explicit UTC/millisecond syntax above. The starting bucket is rounded forward to a complete UTC boundary.

Generates only contiguous complete buckets whose final base candle is closed, using exact decimal volume sums. It records `derived_from`/derivation version in candle payloads, replaces derived buckets in the requested interval, removes previously derived buckets now known to contain gaps, invalidates affected target features and rebuilds them with the **same** FeatureBuilder/FeatureEngine/traits used by M2. It refuses to overwrite any existing target-series exchange candle or a different derivation. Do not run exchange fetching into a series reserved for derivation. Derived history can be recreated from retained base candles. No base candles are changed. Invalid input and time-budget failures abort the operation; there is no candle-count cap or independent technical feature system.

Prints JSON with candle/feature counts, source/target periods and range. On demand only: no added fetch stream, schedule or permanent worker. Train and inspect timeframe-specific intelligence with the ordinary M4 commands after derivation; automatic weekly dispatch continues to use the feed's selected base period. Derived models remain separate per timeframe and are not silently blended into a single recommendation.

```bash
php artisan trademinator:derive-timeframe kraken BTC/USD 1m 5m --from=2026-09-20
php artisan trademinator:knn-build kraken BTC/USD 5m
php artisan trademinator:signal kraken BTC/USD 5m
```

### Reading intelligence readiness

The market intelligence page distinguishes live potential history from the frozen model's last training run. **Knowledge rows** shows the retained example count and new models record their history window. Live counts use the same age boundary as the next build and warn when the configured days cannot fit the validation minimum at the selected candle period. With the default 12-candle horizon and KNN settings, at least **405 contiguous, complete usable rows** permit minimum validation; **468** permit a full tuning block. These counts include the purges at both chronological splits and the separate 20% holdout. Rows reserved for pattern training are excluded first. Actual validation must still pass.

The page lists each K-selection and holdout requirement, missing selected features, stale collection, dataset exclusions, and pattern sample counts. Live potential rows are an upper bound before source, semantic-warmup and pattern checks. The data ETA assumes continuous collection and complete future features; it is unavailable when the observed inputs do not support an estimate. It is not a promise of validation success or a directional signal. The next scheduled dispatch is shown separately from data availability and does not confirm worker health.

An abstaining model does not absorb newly collected rows automatically. After more history or corrected features are available, run a direct `trademinator:knn-build` for that market to reevaluate immediately. Redispatching a completed weekly generation does not rebuild it. Older models remain readable; rebuild once to record exact post-pattern history counts and frozen pattern thresholds. The page's meters refresh when the page is reloaded.


## M4.1 cross-exchange lead/lag and M4 completion

This release preserves the M4 readiness/progress/ETA UI and successful-backfill → M2 features → KNN/pattern rebuild workflow. It fixes pattern selection using its own final evaluation block, adds versioned evidence weights to KNN, and adds explicit Bull / Bear / Super Bull / Super Bear descriptions. These descriptions summarize the validated KNN signal, not a separate price forecast: BUY maps to Bull and SELL to Bear; Super additionally requires confidence at least 0.8 and at least 6 effective neighbors. HOLD and abstention map to Neutral. The thresholds are recorded in each model. Lead/lag can affect these states only through the validated downstream KNN; it never forces a trade or raises confidence by an arbitrary bonus.

### Upgrade from M4 or an earlier release

Deploy the full source while preserving `.env`, the database and writable `storage/`. The tarball excludes dependencies and all runtime/user data; it includes compiled frontend assets. Install locked dependencies, apply pending migrations, and refresh configuration/metadata:

```bash
composer install --no-dev --prefer-dist --no-interaction
php artisan optimize:clear
php artisan migrate --force
php artisan trademinator:refresh-exchanges
php artisan config:cache
php artisan schedule:list
php artisan trademinator:dispatch-market-intelligence
php artisan trademinator:dispatch-lead-lag
```

Drain the existing `intelligence` queue using [CRONTABS.md](CRONTABS.md); no additional system cron or daemon is required. Existing M4 models are retained for inspection but abstain with `model_version_mismatch` until rebuilt under the corrected validation version. Weekly generation keys include that version, so redispatching after this upgrade can rebuild a model already completed this week. A direct `trademinator:knn-build EXCHANGE SYMBOL PERIOD` also rebuilds immediately. Do not run `migrate:fresh`, regenerate APP_KEY, or replace the deployment's storage/database.

Tests require dev dependencies: install without `--no-dev` in a development checkout, then use `php artisan test --compact`; production installations without dev packages may not expose `artisan test`. The suite uses SQLite `:memory:` and ignores deployment database settings and configuration caches. `phpunit.xml` sets a **512 MiB test-process memory limit**, including when PHP starts with its 128 MiB default. This applies through Artisan, Composer and direct Pest runs. The separate market-catalog memory regression still runs its child process at 128 MiB.

The initial M4.1 archive omitted this test memory setting. The complete suite could exhaust 128 MiB during a bounded database fetch even though the same test passed alone. For a checkout that still has the original `phpunit.xml`, use the following temporary command, or apply the corrected full archive:

```bash
php -d memory_limit=512M vendor/bin/pest --compact
```

Set the option on the direct Pest process: `php -d memory_limit=512M artisan test` does not forward that PHP option to Artisan's test subprocess. This test setting does not alter the web or queue PHP configuration; intelligence workers retain the explicit 512 MiB setting documented in [CRONTABS.md](CRONTABS.md).

The new migration adds `exchanges.timezone`, `timezone_source`, and `region_prior`. Every exchange has an explicit IANA timezone, default UTC with source `unknown`. Refresh reads installed CCXT country metadata offline: a single country with exactly one IANA zone seeds a `country_prior`; ambiguous or missing metadata retains UTC/unknown. Unknown timezone records use only the all-hours model. Country metadata is not customer geography, nor evidence that an exchange leads. Select a local reference explicitly when appropriate:

```bash
php artisan trademinator:exchange edit bitso --timezone=America/Mexico_City
php artisan trademinator:exchange edit ndax --timezone=America/Edmonton
php artisan trademinator:exchange list
```

These are operator choices, not claims about the location of an exchange's traders. Overrides survive refresh. A changed leader timezone suppresses its old session feature until retraining. DST is handled by IANA rules; UTC alignment of candles never changes.

### Evidence and causal use

For each target, M4.1 examines up to eight other actively subscribed exchanges, sorted by CCXT ID. It compares the exact same spot symbol and quote currency at the same selected fixed-duration period. Different periods are reported as unavailable; different quotes and derivative symbols are excluded. Calendar-month/year bars are not supported by this timing estimator. It does not assume USD, USDT, CAD or other quotes are equivalent, and it does not create additional subscriptions or call exchanges. Use the feed's selected reliable period. A one-day candle cannot reveal a delay of a few minutes; the model never claims sub-candle precision.

Source data are bounded to 6,000 bars per peer before the auxiliary cutoff. Observations are log returns at matching UTC candle-close times. Missing, zero-volume, invalid, flat or unclosed candles are excluded; gaps are never filled or paired by row number. Each lag requires a complete intervening interval. Source reads share a repeatable database snapshot, and the artifact records exact data fingerprints, model coefficients, local context, row counts, selected lag/session, validation boundaries and metrics.

The target knowledge snapshot's first 40% boundary is the latest permissible auxiliary cutoff. Each directed relationship tests delays of 1–6 candles, all hours, and (when a timezone is known) four six-hour leader-local sessions. The initial 60% fits a two-input return regression (leader return plus the follower's own current return); the next 20% selects the lag/session; the final 20% tests the frozen winner. All candidates share the same chronological boundaries, and labels crossing either boundary are purged. Both positive and inverse relationships can qualify. Reverse-direction correlation is reported and must be at least 0.05 weaker than the forward residual correlation.

Default gates require at least 160 observations and 30 rows in each block, residual correlation at least 0.2 in the fitted direction, at least 5% error improvement over **both** the local autoregressive baseline and the training-mean baseline, a positive Fisher-transformed lower evidence bound (z=3.5, with an autocorrelation-adjusted effective count), and positive improvement with consistent direction in all three later subperiods. This is a conservative screening heuristic, not a guarantee of causality or a calibrated probability. The report also exposes leader volume, candle range and follower return volatility; these are observed context, not assumed regional weights. Statistical pass rates and predictive value still require real-market evaluation.

Only accepted relationships add normalized features to the final KNN. Pattern classifiers continue to reuse the original normalized M2 inputs. KNN rows must come strictly after the complete auxiliary validation horizon; the report counts these exclusions. Each accepted leader contributes a signed volatility-normalized return feature, attenuated by evidence strength and capped at 0.25 influence. Its distance weight is also bounded; missing, expired, disabled or out-of-session evidence has weight zero and is removed from both the RMS numerator and denominator. The same weighting rules apply during K tuning, final validation and live inference. Historical observations unavailable for a sample are also given zero weight. This prevents a placeholder from inventing evidence or making unrelated dimensions appear closer.

Evidence expires 14 days after its validation horizon by default. With coarse periods or long snapshots, many rows may therefore have no usable lead/lag evidence; counts do not guarantee an active feature. Changing source availability at inference does not alter stored models. Old model reports remain immutable, and rebuilding can publish an abstaining replacement if a formerly useful relationship or downstream model fails. Repeatedly tuning thresholds against the reported test block would make it development data; keep later real-market data for ongoing evaluation.

Settings are in `config/lead_lag.php`; `LEAD_LAG_ENABLED=false` prevents new relationships and zeroes their inference weight, while `LEAD_LAG_DAILY_REFRESH=false` disables only the extra daily dispatcher. Rebuild after changing settings. Existing models retain recorded fit/evidence thresholds. The existing weekly and backfill builds also run M4.1 preparation when enabled. No valid peer leaves core KNN/pattern processing available.

## trademinator:dispatch-lead-lag

Description: Queue daily lead/lag and downstream intelligence reevaluation for overlapping subscribed markets

Signature: `trademinator:dispatch-lead-lag`

No arguments/options. Scheduled daily at 03:45 in the application timezone. Requires migrated tables, active subscriptions on at least two distinct exchanges with the exact same symbol/selected period, stored closed candles, M2 features, a persistent queue, shared atomic cache locks and shared private model/research storage. Uses `INTELLIGENCE_QUEUE`, default `intelligence`. It queues one combined lead/lag + KNN/pattern build per matching shared feed, with a per-day/version generation key. Queue uniqueness, per-market build locks and database generation uniqueness protect redelivery. Repeating the command does not re-train a completed daily generation. Use direct `knn-build` to force reevaluation. Disabled intelligence/lead-lag/daily refresh exits successfully without dispatch; sync/null queues fail. Worker failures appear in the normal failed-job reporting; automatic training retains 480 seconds, human training receives a separate 300 seconds plus 10 seconds for publication, and the job timeout is 900 seconds. No orders, messages or external API calls.

```bash
php artisan trademinator:dispatch-lead-lag
php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --timeout=900 --memory=512 --tries=3
php artisan queue:failed
```

This dispatcher reevaluates the complete downstream model so current lead/lag coefficients are never silently swapped into a KNN trained with different features. The existing weekly schedule remains, including for markets without matching peers. Daily rebuilds retain new dataset/model artifacts; monitor private storage usage.

## trademinator:dispatch-market-signals

Description: Queue current signal recording once per actively subscribed market

Signature: `trademinator:dispatch-market-signals`

No arguments or command-specific options. Scheduled every minute with shared scheduler locks. Requires the M4.2 migration, a persistent database/Redis queue, shared atomic-lock-capable cache, shared model storage, an active market subscription and a selected feed period. `sync` and `null` queue connections fail. `DASHBOARD_SIGNALS_ENABLED=false` or `INTELLIGENCE_ENABLED=false` makes dispatch and already queued recorder jobs idle.

Queues a unique `RecordMarketSignal` job per shared market on `INTELLIGENCE_QUEUE` (default `intelligence`), regardless of subscriber count. The job allows two attempts, a 120-second timeout and a 60-second retry delay; the existing intelligence worker and `retry_after >= 1080` remain sufficient. Uniqueness lasts up to five minutes; a per-market recording lock and observation deduplication also protect repeated delivery. A queued job rechecks that an active subscriber still exists and reads the current selected period. Queue backlog can delay observations; the command does not backfill missed decisions.

Side effects: appends the current model/source/action/reason/evidence to `market_signals` with its actual recording time. Repeated consecutive observations reuse the saved record; a later source candle or an intervening-state recovery creates a new record. Missing or unusable intelligence records waiting evidence, not a fabricated BUY/SELL. Existing observations are never recomputed with a newer model. This command performs inference only; it does not train, subscribe, allocate funds or send orders. Client execution remains Unknown.

```bash
php artisan trademinator:dispatch-market-signals
php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --timeout=900 --memory=512 --tries=3
```

Install the existing [intelligence cron worker](CRONTABS.md#m4-intelligence-workers); no additional system cron or permanent daemon is required. The [M plan](M-PLAN.md) describes marker timing, access and the future Client reporting contract.

## trademinator:refresh-market-discovery

Description: Refresh bounded CoinGecko discovery and market conditions without subscribing or trading

Signature: `trademinator:refresh-market-discovery`

No arguments or command-specific options. Scheduled hourly at minute 10 in the application timezone, in the background so network latency does not hold up subsequent scheduler commands. A shared 15-minute refresh lock also prevents concurrent manual runs. Requires the configured shared cache, outbound HTTPS and `COINGECKO_API_KEY`. `DASHBOARD_DISCOVERY_ENABLED=false` or `COINGECKO_ENABLED=false` skips all HTTP work successfully. Missing credentials, invalid/stale source data or HTTP errors fail with a generic console message and diagnostic application logging.

Reads `/global`, one `/coins/markets` page of at most 100 assets ordered by volume, up to `dashboard.discovery_limit` exact-symbol `/search` checks (default 12, hard maximum 20), and `/coins/categories`. Thus a default refresh makes at most 15 requests and the maximum configured refresh makes 23. Each uses the existing CoinGecko client's 10-second connection and 30-second request timeouts. Exact-symbol duplicates and mismatched coin IDs are excluded; known ambiguous/conflicting exchange-market mappings cannot receive an activity boost. Finite positive market caps, nonnegative volumes and source timestamps are required. Discovery is optional and remains subject to the CoinGecko account's quotas.

Side effects: atomically replaces one shared cached discovery snapshot after successful validation. Keeps up to 12 resolved assets by default, global conditions and up to five category movements. Context expires no later than two hours after any included source timestamp. An outage preserves existing context only until its source expiry; preference screens continue without activity after that. No market, feed, subscription, candle, training-context snapshot or trade is created. Ranking uses coin-wide volume/market-cap activity only to break ties between preference matches; it does not establish pair liquidity or imply trading authorization.

```bash
php artisan trademinator:refresh-market-discovery
```

This is separate from `trademinator:collect-market-context` and the targeted `trademinator:fetch-market-context`, whose timestamped snapshots supply CoinGecko context eligible for M2 training. See [M4.2 operating notes](CRONTABS.md#m42-dashboard-recording-and-discovery).

## trademinator:archive-tickers

Signature: `trademinator:archive-tickers {exchange} {symbol} {period} {month : YYYY-MM} {--dry-run}`

Description: Export one immutable monthly ticker shard to verified cold storage without pruning hot rows.

Creates one monthly gzip JSONL shard and adjacent manifest. `--dry-run` reports the planned files and source row count without writing the archive.

## trademinator:archive-eligible-tickers

Signature: `trademinator:archive-eligible-tickers {--dry-run}`

Description: Export and verify complete ticker months older than the configured hot-retention boundary; never prunes rows.

Scans stored market/period series and archives complete months older than `ARCHIVE_AFTER_DAYS`. This is the scheduled M4.3 command. It deliberately leaves all hot rows untouched.

## trademinator:archive-verify

Signature: `trademinator:archive-verify {manifest? : Relative manifest path}`

Description: Verify one or all archive shards, including checksum, row count and boundary keys.

With no argument, verifies every manifest under the configured archive root and reports catalog gaps, overlaps and failures.

## trademinator:archive-rebuild-catalog

Signature: `trademinator:archive-rebuild-catalog`

Description: Rebuild the active archive catalog by scanning and verifying filesystem manifests.

Use after catalog loss or migration. The filesystem manifests remain the reconstructable source for archive metadata.

## trademinator:archive-restore

Signature: `trademinator:archive-restore {manifest : Relative manifest path} {--from= : Inclusive millisecond timestamp} {--to= : Inclusive millisecond timestamp} {--validate-only}`

Description: Validate or restore one verified archive shard into hot storage without overwriting conflicts.

The optional range must remain inside the selected manifest coverage. Identical rows are idempotent; conflicting rows fail.

## trademinator:portable-export

Signature: `trademinator:portable-export {path} {--dataset=* : Logical datasets; currently tickers}`

Description: Create a database-independent gzip JSONL export. Secrets are excluded.

The initial portable dataset is ticker history. Application keys, passwords, exchange/API credentials and other secrets are not exported.

## trademinator:portable-import

Signature: `trademinator:portable-import {path} {--validate-only}`

Description: Validate or import a portable gzip JSONL package without silently overwriting conflicts.

Run with `--validate-only` before mutation. Identical records are accepted on re-import; differing records fail instead of being overwritten.

## trademinator:human-training-export

Signature: `trademinator:human-training-export {path : New private JSONL output file}`

Description: Export versioned human training snapshots and reviews without account secrets

Arguments: `path` is a new JSONL filename in an existing writable private directory. No options, aliases or defaults. Existing files are never overwritten. Requires the M4.4 migrations and a trusted operator shell; web export additionally requires the server owner.

```bash
php artisan trademinator:human-training-export storage/app/private/human-training-2026-09-30.jsonl
```

Streams submitted reviews and their frozen snapshots in batches of 100, with a versioned manifest and SHA-256 footer covering all previous JSONL bytes. Financial candle values remain decimal strings; normalized features remain JSON numbers. UUID trainer provenance and optional notes are included, but account secrets, names and emails are excluded. The output is private (0600); incomplete files are removed on failure. A zero-review export contains a manifest and checksum. Import is not supported. There is no new schedule or queue requirement.

The existing `trademinator:knn-build` command and scheduled builds publish two independent KNNs; the command signature is unchanged. Automatic KNN retains objective future-outcome labels and validation. Human Candle KNN uses the same selected technical features without CoinGecko, learns authorized BUY/HOLD/SELL annotations, and validates against held-out human actions. Human Trend is excluded from scoring. Its flag does not re-enable production influence.

`INTELLIGENCE_AUTOMATIC_WEIGHT=0.40` and `INTELLIGENCE_HUMAN_CANDLE_WEIGHT=0.60` set relative weights frozen at build time. Each supported model contributes its full BUY/HOLD/SELL distribution. An abstaining/unavailable model has zero effective weight; remaining weights renormalize. A supported HOLD is evidence, not an abstention. The winning combined score times weighted mean similarity must reach `INTELLIGENCE_ENSEMBLE_MIN_CONFIDENCE=0.60`; ties abstain. These are heuristic evidence scores, not profit probabilities. Social/news has no connected scoring provider and explicitly contributes zero; CoinGecko is context inside the automatic KNN.

The report adds `ensemble` and `automatic`, plus independent `candle_guidance` metadata: technical `input_keys`, sample count, policy selection, chronology, annotation provenance and held-out annotation agreement. Top-level `selection` and `holdout` describe the automatic model only. Public reports omit both knowledge vectors. Published signal payloads and Client `server_signal.scoring` / `market.signal.scoring` expose component actions, scores, confidence, evidence reasons and configured/effective weights. New directional `action_meaning` values are `supported_buy_by_weighted_models` and `supported_sell_by_weighted_models`; legacy signals keep their meanings. The action enum (`buy`, `hodl`, `sell`) is unchanged; scoring distributions use the human-readable `hold` key.

For example, exact-match automatic BUY scores of `(1, 0, 0)` and human SELL scores of `(0, 0, 1)` produce combined `(0.4, 0, 0.6)`, final SELL with 0.60 confidence, and effective weights 0.40/0.60. If the human model abstains, automatic receives effective weight 1.0. Both unavailable means abstention.

Version `m5-two-knn-v1` requires rebuilding existing stacked models; their datasets and annotations remain stored. No migration, dependency, signature or schedule changes are needed. After clearing/regenerating configuration and restarting workers, run an explicit build for each market/selected period; a completed weekly generation is idempotent and will not force a rebuild. See [INTELLIGENCE-RECOVERY.md](INTELLIGENCE-RECOVERY.md) for rollout and independent validation details.


### Exchange credentials used by collection commands

`trademinator:fetch-ohlcv`, `trademinator:sync-ohlcv`, `trademinator:select-candle-period`, and jobs dispatched by the feed, history and candle-repair dispatchers resolve credentials when executing. For a shared market feed, personal keys of active, non-suspended subscribers take precedence; multiple personal keys rotate. Otherwise, credentials explicitly shared by current, non-suspended server owners rotate per exchange. Shared owners participate in the owner pool even when they subscribe to that market. A user-scoped operation always prefers that user's own keys. Unrelated users' private keys are never selected. Existing exchange JSON credentials remain the final fallback.

Configure read-only API keys in **Settings → Exchange keys**. Stored values are encrypted with Laravel's application key and never returned by the settings page. Preserve the same `APP_KEY` on every worker/server and in backups. Only owner UUIDs configured by the operator may share keys. `OWNER_UUID` remains supported; `OWNER_UUIDS` accepts additional comma-separated UUIDs. After changing owner configuration, rebuild configuration cache and restart long-running workers normally. Changing ownership excludes former owners from the shared pool. Rotation uses database transactions with conflict retries, so it coordinates across workers sharing the database without requiring shared Redis state. It distributes operations, not necessarily each individual HTTP page; per-account/IP quotas and existing exchange rate limits still apply. No secrets are stored in jobs or rotation cursors.

Coinbase Advanced Trade uses CCXT's authenticated market-data endpoint when credentials are present, including the `fetchOHLCV.usePrivate` option. A CDP key uses the full key name and the ECDSA signing key (PEM line breaks, or escaped `\n` line breaks). Without credentials, the existing public path remains available. Never grant trading, transfers or withdrawals; restrict to public market information wherever the exchange permits. Permission selection must be checked at the exchange: this form cannot automatically verify it. Wallet private keys are not accepted.

Run `php artisan migrate --force` when installing this change, rebuild frontend assets with `npm run build`, and restart active queue workers. Existing crontab schedules are unchanged. Saving credentials makes affected blocked/error/pending feeds due again without replacing a live lease; the normal dispatcher picks them up. Already-running operations may finish with the credentials they selected before a key was changed or removed.
