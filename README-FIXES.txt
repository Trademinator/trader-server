Trademinator - dashboard and queue memory fixes (before M5)
Date: 2026-09-30
Revision: 2 (dashboard layout)
Source commit: 5c5d5e2e8cc0818ecac7ebaf122c9ffe6011f287

This is the complete application source plus rebuilt frontend assets.

Changes
- Validated models X/Y covers all markets followed by the signed-in user,
  across dashboard pages and search filters. Green checks and chart markers
  remain; the page-limited wording is removed.
- Exchange logos are 24px tall with proportional widths, to the left of
  exchange names. A letter fallback appears if a logo is unavailable.
- Needs attention has its own container, collapsed by default. Click the
  overview card or section title to toggle it. Live search preserves its state.
- Live search matches partial pair, exchange name or period without a reload.
- Only the owner sees the Needs attention statistic, section, detailed errors
  and suggested recovery commands. Healthy ready feeds are no longer flagged.
- Live collection and history keep only the requested market in the exchange
  indexes, bound candle requests, and discard retained SDK response copies.
- Training caps are checked before dataset rows are read. Human-review
  snapshots are read in batches; all queues release unused memory after jobs.
- All three queue worker commands have explicit PHP and Laravel memory
  thresholds, and recycle between jobs. See docs/CRONTABS.md.

Deploy over your existing installation
1. Back up your application and database. Let running jobs finish and pause
   the scheduler/queue cron entries while updating. Preserve the existing
   .env, APP_KEY, database, private research/models and writable storage.
   From the application directory, extract:

   tar --no-same-owner -xzf /path/to/trademinator-dashboard-queue-fixes-full-20260930.tar.gz --strip-components=1

2. Install locked PHP dependencies and refresh application caches:

   composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
   php artisan optimize:clear
   php artisan migrate --force
   php artisan config:cache
   php artisan view:cache

   This fix adds no migrations or dependency changes relative to main
   73d576e82472a569456b2b7a18ec26425573e31a. The migrate command applies only
   pending migrations if your installation is older. Never use migrate:fresh.
   Keep OWNER_UUID set to the existing owner's users.user_id.
   public/build already contains the production frontend bundle; no Node.js
   build is needed on the deployment host for this archive.

3. Replace the default, intelligence and history worker cron commands using
   docs/CRONTABS.md, then resume the scheduler and workers. Keep the queue
   retry_after setting at least 720 seconds. Default settings are:

   default:      PHP 512M, worker --memory=384, --timeout=600, --tries=5
   intelligence: PHP 512M, worker --memory=384, --timeout=600, --tries=3
   history:      PHP 256M, worker --memory=192, --timeout=120, --tries=1

   All use --stop-when-empty --max-time=50 and the existing local flock.
   --max-time is checked between jobs; a running job can take longer.
   Raising PHP memory_limit alone does not change Laravel's separate default
   128 MiB worker threshold, which can report "Memory limit exceeded".

4. Check:

   php artisan schedule:list
   php artisan queue:failed

   Review the three queue logs. Sign in as the owner and use the specific
   recovery command shown for an affected market, if one is still needed.
   Existing models do not need rebuilding solely because of this update.

Validation
- This UI revision: 9 dashboard PHP tests passed (104 assertions), using
  SQLite :memory:.
- 8 dashboard JavaScript tests and the production asset build passed.
- The preceding queue fix passed 154 related PHP tests (1,677 assertions).
- Laravel Pint and git diff --check passed.
- In the preceding queue fix, a 5,000-pair exchange fixture completed three
  collection passes and 250
  features under a 128 MiB PHP cap, peaking at 124,428,288 bytes.
  This fixture is not a guarantee for every live exchange or production RSS.
- The complete application test suite, live exchange APIs, production
  MariaDB and a browser visual check were not run for this fix.

Package integrity: from an extracted copy, run sha256sum -c SHA256SUMS.
Do this before editing deployment files. Historical milestone checksum
files describe earlier releases; SHA256SUMS describes this package.

Excluded: .git, vendor, node_modules, .env, database data, licensed GeoIP
files, runtime storage, queue lock files and application caches.
M5 functionality is not included in this update.
