# M3 R3 — Reviewed CCXT market-data access

R3 inspects all **110 synchronous PHP adapters in the locked CCXT 4.5.57 package** and records three effective OHLCV access states: **89 public, 4 authentication required, 17 unknown/unsupported**. The [complete matrix](CCXT-OHLCV-CLASSIFICATION.md) lists every adapter. Detailed source references, endpoint signing results and branch notes are in `resources/data/ccxt-access-reviews.json`.

## What the classifications mean

- **Public:** the reviewed default path can load the necessary market data and request candles without API credentials according to this adapter's source.
- **Authentication required:** a mandatory step requires credentials. Market discovery and the candle endpoint are recorded separately: Alpaca has public crypto candles but authenticated asset discovery; Luno has public markets but authenticated candles. ModeTrade and WOOFi Pro also require candle authentication, but neither advertises spot support.
- **Unknown:** no current matching review establishes a usable native OHLCV path. This includes unreviewed/changed/failed inspections and unsupported or unadvertised OHLCV. In this release the 17 unknown cases are all in the unsupported/unadvertised category. Indodax has a public implementation but does not advertise `has.fetchOHLCV`; it remains excluded.

The review follows `fetch_ohlcv`, `load_markets`/currency discovery, exchange-specific helpers, implicit request endpoints and signing. It checks inherited implementations and optional credential branches. For example, Binance's optional private currency/margin enrichment does not make its unauthenticated spot OHLCV path private. Endpoint names alone and `requiredCredentials` alone are not used to infer authentication. The source review was supplemented by offline signing of discovered endpoints with an empty client; no live exchange calls or customer credentials were used.

This is a classification of the **locked adapter's default behavior**, not a successful connection test or a guarantee of regional access, data entitlement, current availability, every instrument, custom endpoint, sandbox or nondefault option. Stored credentials can enable optional private requests even on a public adapter. HTTP errors and geographic restrictions do not automatically change this registry into an authentication-required classification. Existing runtime error descriptions remain available.

## What changes in the application

The market selector requires a current reviewed classification, native `has.fetchOHLCV === true`, spot support and at least one supported timeframe. **75 adapters qualify: 73 public plus Alpaca and Luno.** Authentication-required choices show an API-access label. Before loading pairs or accepting a subscription, missing required credential fields produce an explanatory error. The check validates field presence only; CCXT/the provider still validates credentials.

Unknown and removed adapters are excluded from choices and rejected on direct options/subscription requests. Validating metadata happens **before** reading cached exchange choices or pairs, so an old successful cache cannot bypass the restriction. CLI subscription creation/reactivation uses the same access gate, with its existing market-type behavior. Explicit administrative data/backfill commands retain their existing CCXT behavior; they are not a way to add an unreviewed subscriber feed.

Existing users, exchange configuration, markets, candles, research data and subscriptions are preserved. Removed exchanges are **delisted, not deleted**. Existing subscriptions remain visible. Automatic shared feeds for unknown/removed adapters pause with `blocked` status and an explanation; they recheck after six hours and can resume when a matching review returns. The same early check blocks an authentication-required feed with missing credentials. M2/M3 can still process already stored data.

## Automatic refresh

1. **Composer install/update/dump-autoload:** an offline hook inspects the installed CCXT package and atomically writes local runtime metadata. It does not boot Laravel for the scan, access the database, read configured credentials, call an exchange, or download a newer CCXT version.
2. **Daily at 03:20:** `trademinator:refresh-exchanges` rebuilds local metadata and adds missing database exchange rows. It runs on every application node through the existing scheduler cron. Local file locking serializes scans; a shared cache lock serializes database inserts. Existing rows/settings are retained.
3. **Every catalogue/subscriber-feed check:** version, review-file hash and source-file hashes are validated before cached data or network calls are used. New/changed adapters cannot remain public until the next daily scan. If the package and snapshot versions disagree, a maintenance message is shown until refresh completes.

Source fingerprints cover the full concrete adapter, inherited concrete and abstract classes, and shared `php/Exchange.php`. A change in shared code conservatively invalidates all dependent reviews, including a change that turns out to be harmless. Refresh detects that change but does **not** automatically approve new code. This may temporarily hide all exchanges after a CCXT update; update the reviews alongside an intentional dependency upgrade before deploying it.

The checked-in snapshot is `resources/data/ccxt-exchanges.json`; local overrides are `storage/app/private/ccxt-exchanges.json`. An obsolete runtime file preserved from an older deployment cannot shadow a bundle with the current package/reference/review hash. Each adapter is described in its own 128 MiB child process; no web request instantiates every adapter. A failed inspection becomes unknown rather than retaining its old public label. Metadata publication is atomic and has no database migration or deletion step.

## Upgrade from M3 R2

Back up the deployment database. Retain the existing `.env`, `APP_KEY`, database and `storage/` contents. Extract the **full R3 archive into a separate directory**, inspect it, then copy its application source/compiled assets into the existing installation while retaining those deployment files. Deploy the bundled reviews, metadata, scripts and test-isolation files together.

From the installed project directory:

```bash
composer install --no-dev --optimize-autoloader
php artisan trademinator:refresh-exchanges
php artisan view:clear
php artisan schedule:list
```

The Composer hook builds runtime metadata automatically; the Artisan command also adds any missing exchange database entries. Do not run `composer update` merely to install this release. Ensure the deployment user and PHP/cron user can read the snapshot and write `storage/app/private/` and `storage/framework/` using your normal ownership policy. If Composer scripts are intentionally disabled, run `php scripts/build-exchange-metadata.php --runtime` manually before serving traffic.

Reload PHP-FPM/FCGI as required by your OPcache policy. Use atomic application/vendor deployments; source hashing does not replace reloading long-running processes with old loaded classes. R3 adds **no migration or dependency-version change**. It corrects the pre-existing Composer lockfile root platform metadata/content hash to match `composer.json` (PHP 8.4/8.5, `ext-intl`, and development `ext-pdo_sqlite`); all locked package records remain unchanged. Existing M3/R2 databases need no migration for this repair. An installation still on M2 must follow the original M3 migration instructions separately. Never run `migrate:fresh`, `migrate:refresh`, `db:wipe` or key generation to apply this repair. R1's guarded SQLite in-memory test configuration remains included. No application-wide cache flush is needed.

The two existing cron entries remain sufficient; see [crontabs.md](crontabs.md). `schedule:list` should now also show the daily refresh. Full command signatures/options are in [CLI.md](CLI.md#trademinatorrefresh-exchanges).

## Reviewing a CCXT update

Make the dependency change in a development checkout first. The installed source, not a remote “latest” list, is the source of truth. Capture a report:

```bash
php artisan trademinator:refresh-exchanges --check --json
php scripts/build-exchange-metadata.php EXCHANGE_ID
```

The report lists current source fingerprints, capabilities, added/removed/changed IDs and `needs_review`. Inspect each affected `vendor/ccxt/ccxt/php/EXCHANGE_ID.php`, its parents/abstract endpoint wrappers and `php/Exchange.php`. Trace market/currency discovery, OHLCV helpers, default options and signing. Check relevant provider documentation if the adapter's public/private routing is ambiguous. Record `unknown` if authentication cannot be established; do not infer public access from a lack of the word “private” or from a failed request.

After actual review, update that ID in `resources/data/ccxt-access-reviews.json`: `markets`, `candles`, a substantive `note`, `evidence` method/file/line references, and the **reviewed** `source_fingerprint` from worker output. The included `endpoint_signing` evidence documents low-level routing; it includes optional branches and is not proof that every recorded route runs by default. Do not bulk-copy fresh hashes merely to restore visibility. A common-base change requires reviewing its effect on all dependants. Update the review date/package reference and classification matrix, then rebuild the release bundle and run the relevant tests:

```bash
php scripts/build-exchange-metadata.php
php artisan test --filter='ExchangeAccess|MarketCatalog|MarketSubscriptions|ExchangeRecovery|TrademinatorCliDocumentationTest'
```

Tests intentionally assert this release's 110-adapter inventory and classification totals; update those expectations after a genuine dependency/source review. The refresh tool never edits the review manifest. There is no “approve everything” flag. A new reviewed public adapter appears once it also has eligible spot/candle capability and a database row; a removed adapter stays out of the choices while its history remains intact.

## Later subscriber credentials

This release does not collect subscriber API keys. The classification distinguishes the exchanges that would benefit from that later feature. Open source makes its behavior auditable, but read-only access still needs to be enforced by the exchange's permissions. A later implementation should use explicit consent, encrypted per-subscriber storage, revocation, no secret logging and a deliberate policy for whether authenticated data can be shared across subscribers. Never store subscriber keys in the existing shared exchange configuration or shared public-pair cache. Credentials do not change geographic eligibility.

## Validation scope

Regression coverage includes source changes in inherited/common files, missing reviews, unsupported/emulated capability, added/removed IDs, obsolete runtime metadata, rejection before cached-pair access, Luno credential preflight, data-preserving/idempotent refresh, read-only checking and paused feeds. Existing error handling, database-safety and 128 MiB/5,000-pair memory tests are retained. Provider reachability, actual key validity, regional access and production multi-node concurrency require deployment checks; no live API calls were made for this release.

Release checks: **185 PHP tests / 1,240 assertions**, **4 browser-script tests**, CLI documentation gate, Composer validation/platform requirements and refresh hook, Pint, and frontend build passed. The 128 MiB fixture probe measured **50,855,936 bytes** for the exchange page and **128,622,592 bytes** for the full 5,000-pair/subscription flow; this is a fixture measurement, not a bound for arbitrary live responses.
