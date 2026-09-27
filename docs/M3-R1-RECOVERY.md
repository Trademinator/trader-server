# M3 R1 — Database test isolation and empty exchange catalogue

R2 retains these database safeguards and fixes catalogue memory use and provider errors. For an exchange list that loads but pairs fail, see [M3-R2-MARKETS.md](M3-R2-MARKETS.md).

## What was wrong

The original M3 package inherited the repository's `phpunit.xml`, which set `APP_ENV=testing` but did not force a separate database. Feature tests use Laravel's `RefreshDatabase`; its first refresh calls `migrate:fresh`. Running `php artisan test`, `composer test`, Pest or PHPUnit with that configuration could therefore drop and recreate the application's tables using the connection from `.env` or cached configuration. Test-created users are normally rolled back, leaving an empty users table.

This is a confirmed unsafe code path, not proof of which command ran on a particular server. M3's normal `migrate --force` migration creates only `research_datasets` and `research_backtests`; it does not clear users or exchanges. The full source tarball contains no `.env`, application database or deployment configuration cache. If tests were not run, inspect the installation commands and active database connection before concluding that records were deleted rather than a different database being selected.

An empty `exchanges` table results in an empty `/markets` exchange selector. The list can also be cached empty. The old page offered no explanation.

## Preserve evidence and choose the database

Stop running the original package's tests. Avoid `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, and rollback operations on the application database. Preserve your shell history and deployment logs. If data recovery is needed, pause application writes and the two cron entries while assessing/restoring a backup; keep a copy of the current database first, including any newly registered user.

The following commands only inspect the current connection and exchange records:

```bash
php artisan db:show
php artisan trademinator:exchange list
```

Confirm that the reported database is the intended deployment database. Compare `.env` database settings with the deployment's existing configuration; do not publish passwords or API keys. A stale configuration cache or a changed environment can point the app at a different database. If the original database still contains your records, reconnecting to it may be sufficient.

If records were actually deleted, this code update cannot recreate the previous users, UUIDs, subscriptions, settings, candles or feature history. Restore an appropriate backup, preferably into a separate database for inspection first; point-in-time recovery may be possible if the host retained the required database backups and binary logs. Do not blindly import a backup over the newly registered user's data. Re-fetchable public candles do not replace lost account/subscription records.

## Apply R1

The full R1 archive contains the complete M3 application with the repair. Extract/review it in a separate directory and copy its source changes into the installed M3 project. Keep the deployment's `.env`, `APP_KEY`, database and all existing `storage/` data. Do not run fresh migrations or reinitialize the application. R1 adds no migration and changes no database schema or dependency versions.

Update these safety files together: `phpunit.xml`, `tests/bootstrap.php`, `tests/Support/TestEnvironment.php`, `tests/TestCase.php`, and `composer.json`. The archive also includes the exchange-seeder, page empty-state, regression tests and documentation changes. If PHP OPcache does not revalidate timestamps, reload the relevant PHP-FPM/FCGI service after deploying source updates.

## Restore the exchange selector

After checking the active database and deciding whether to restore a backup, add the exchanges you use. For example, if Kraken is missing:

```bash
php artisan trademinator:exchange add kraken --name=Kraken
```

Use the actual CCXT ID for another exchange. This inserts only that exchange configuration and invalidates the exchange-choice cache automatically. It does not delete or update users, candles, or subscriptions. If the exchange already exists, `add` refuses to overwrite it. Reload `/markets` after adding it. Kraken and Binance offer public catalogue/OHLCV data; requirements vary by exchange. Alpaca's catalogue requires API credentials.

If you intentionally want every installed CCXT catalogue entry, the revised seeder can fill the missing entries:

```bash
php artisan db:seed --class=ExchangeSeeder --force
```

The revised seeder works with empty or partly populated tables, preserves existing exchange IDs/names/configuration and user data, and explicitly invalidates the cached exchange list after the transaction commits. It does not restore deleted custom exchange settings or old subscriptions. Use the explicit `ExchangeSeeder` class; the generic `DatabaseSeeder` still creates a development test user and is not this repair workflow. R1 instantiated every configured adapter to build the dropdown, which could exhaust PHP memory. R2 replaces that behavior with a small offline metadata file.

The page now explains when no exchange is available and disables the empty selector. It does not automatically mutate the database when a user visits it.

## Test safeguards in R1

The standard suite now:

1. Forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=` and test-only cache/mail/queue/session settings.
2. Reapplies those settings before application creation, even with an existing `.env` or inherited shell variables.
3. Ignores deployment config/route/event cache files through process-local paths, without deleting those files.
4. Checks resolved configuration immediately after it loads, before service providers or `RefreshDatabase` can run, and refuses persistent databases or URL/read/write overrides.
5. Removes other named database connections from test configuration. `pdo_sqlite` is mandatory; no MariaDB fallback is allowed.
6. Runs `composer test` without clearing deployment caches.

Run tests in a development checkout with R1 fully installed and development dependencies available. They do not initialize production data. This standard suite intentionally does not support switching to a persistent MariaDB database via environment variables.

```bash
php artisan test --filter='TestDatabaseSafety|ExchangeRecovery'
php artisan test
```

The safety regression starts a subprocess with inherited production/database URL/cache settings, runs fresh migrations on the isolated memory database, and verifies that a disposable persistent database's user, its file checksum, the deployment-cache fixture and `.env` remain unchanged. No real deployment database is used by that regression.

R1 adds no cron task or permanent daemon. Restore the existing cron entries after any data-recovery maintenance is complete.
