# M3 R2 — Pair loading and CCXT memory use

**For the current full release, use [M3 R3 upgrade and access registry](M3-R3-ACCESS.md).** R3 retains these memory/error fixes and replaces the manual-only metadata refresh described below with source-reviewed classification, Composer refresh and daily reconciliation.

“Could not load pairs” indicates a failed request. It does not mean that an exchange has no pairs. The reported `134217728` byte memory limit is 128 MiB; the failure at CCXT `Exchange.php:1758` occurs while decoding JSON.

## Findings and changes

The old exchange dropdown instantiated every configured CCXT adapter in one PHP request, accumulating large generated classes. R2 reads a bundled, version-checked metadata file instead. All 110 installed exchange descriptions are included; only exchanges with spot support, OHLCV and supported candle periods are offered. Friendly names, logos and alphabetical ordering are retained.

The old pair loader called `load_markets()`, which can fetch currencies and build full market indexes. Binance's default catalogue also requests linear and inverse derivatives. R2 retrieves spot metadata and retains only symbols and price precision. For Binance, it calls the public spot `exchangeInfo` endpoint with `showPermissionSets=false`, then uses CCXT's own market parser one record at a time. It avoids retaining thousands of full normalized market objects alongside the decoded response. It neither modifies the vendor package nor raises `memory_limit`.

The web subscription POST reuses the server-side spot catalogue and still validates the selected symbol and price increment. Existing general-purpose CLI subscriptions and collectors retain their market-type behavior. Exchange credentials, custom endpoint configuration, user records and existing subscriptions are not rewritten by this change.

| Exchange | Explanation |
| --- | --- |
| ApeX (`apex`) | The installed CCXT 4.5.57 adapter exposes perpetual contracts and declares `spot=false`. These contracts are not empty, but they do not belong in this spot subscription selector. R2 excludes ApeX and explains unsupported spot markets on a direct options request. |
| Alpaca (`alpaca`) | Its crypto-assets catalogue uses an authenticated assets endpoint. Empty configuration produces an authentication failure. R2 shows a credentials message. Configure valid credentials if you intend to use Alpaca. |
| Binance (`binance`) | The catalogue is not inherently empty. Unnecessary catalogues and large responses can exhaust memory. R2 reduces this work; connectivity, rate limits and regional access restrictions can still independently prevent a request from succeeding. The supplied log alone does not establish which exchange produced the fatal error. |

Expected failures now return a safe code, explanation and reference ID. The matching `Market catalogue request failed.` log entry records the reference, exchange class, failure category and exception class. It excludes raw provider errors, API keys, signed URLs and response bodies. Failed requests are not cached as successful empty lists. The browser displays the server's explanation, distinguishes a successful empty response, and handles non-JSON server errors. PHP out-of-memory fatal errors still require the server log; they are not reliably catchable exceptions.

## Upgrade an existing M3 or R1 installation

Back up the deployment database and retain the existing `.env`, `APP_KEY` and `storage/` contents. Extract the full R2 archive into a separate directory for review, then copy the source into the existing installation while preserving those deployment files. Include `resources/data/ccxt-exchanges.json`, the new classes/scripts/tests and all R1 test-isolation files. Do not initialize a new database or regenerate the application key.

From the installed project directory:

```bash
composer install --no-dev --optimize-autoloader
php artisan view:clear
```

Reload PHP-FPM/FCGI if your OPcache deployment policy requires it, then reload `/markets`. R2 has no new migration, dependency version, queue task or cron entry. New catalogue cache keys bypass the old exchange/pair caches automatically; no application-wide cache flush is required. You do not need to seed exchanges again if they are already present. Normal installation does not require running the tests. Never use `migrate:fresh`, `migrate:refresh` or `db:wipe` on the deployment database.

The full package retains the R1 SQLite in-memory test guard. See [R1 recovery](M3-R1-RECOVERY.md) for prior data loss; R2 cannot restore deleted records.

## Configure Alpaca if needed

Use a protected JSON config file rather than putting credentials in shell history. Preserve any other settings from the existing exchange configuration; an edit replaces the stored JSON configuration. The required keys for the installed adapter are `apiKey` and `secret`:

```json
{"apiKey":"YOUR_ALPACA_KEY","secret":"YOUR_ALPACA_SECRET"}
```

```bash
php artisan trademinator:exchange edit alpaca --config-file=/secure/alpaca.json
```

Use the correct credentials and endpoint configuration for your Alpaca environment. Saving through this command invalidates the pair cache. No credentials are needed for Binance's public spot catalogue.

## Maintain exchange metadata after a CCXT upgrade

The supplied metadata matches the supplied `composer.lock`. Use `composer install` for this release. If you intentionally update CCXT, regenerate the file with the PHP CLI and vendor dependencies available:

```bash
php scripts/build-exchange-metadata.php
```

This offline script loads each adapter in a separate process limited to 128 MiB, and replaces `resources/data/ccxt-exchanges.json` only after all adapters succeed. It performs no HTTP requests or database operations. Deploy the generated file with the new lockfile/vendor version. The exchange-choice cache expires after one hour. A mismatched metadata file produces an explicit maintenance message once the old cache expires. Full script details are in [CLI.md](CLI.md#offline-exchange-metadata).

## Validation and limits

The automated memory regression starts a fresh process with `memory_limit=128M`, the standard guarded SQLite in-memory database, all 110 exchange rows and an uncached `/markets` request. It then uses the real CCXT Binance signing, JSON decoding and market parser with a local synthetic response of **5,000 spot pairs**, including filters and HTTP/body buffer copies. It verifies every pair, the BTC/USDT price increment, one spot request, successful subscription, unchanged exchange configuration, and no full market index. In this PHP 8.4.26 environment the exchange page peaked at about **48.5 MiB** and the complete probe at **120.7 MiB**. These are measured fixture results, not a guarantee for arbitrarily large live responses or a host with additional extensions/debug tooling.

Provider-failure tests cover authentication, denied access, throttling, timeout, connectivity and unexpected errors; failed requests can be retried. Browser-script tests check error messages, references, empty responses and pair rendering. Tests do not call live exchange APIs or verify this deployment's regional reachability. ApeX capability and Alpaca missing-credential checks use the actual installed adapters without HTTP requests.

Run in a development checkout with development dependencies:

```bash
php artisan test --filter='MarketCatalog|MarketSubscriptions|ExchangeRecovery|TestDatabaseSafety'
node --test tests/Frontend/market-catalog.test.mjs
php artisan test
```

If an exchange still fails, retain its selected CCXT ID, timestamp, displayed error message/reference and matching sanitized log entry. Confirm the PHP-FPM/FCGI memory setting separately from CLI PHP; `php -i` alone does not establish the web worker's limit.

Primary references: [Alpaca crypto assets/authentication](https://docs.alpaca.markets/us/docs/crypto-trading), [Binance exchange information and showPermissionSets](https://github.com/binance/binance-spot-api-docs/blob/master/rest-api.md), and the locked CCXT source in `vendor/ccxt/ccxt/php/{apex,alpaca,binance,Exchange}.php`.
