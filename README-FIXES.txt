Trademinator M4 — KNN and pattern intelligence

Built from GitHub main commit 245f9d4e8df383b602897ad7e9f62af9a6fb9363.

Upgrade an existing M3 installation:
1. Back up your database and retain your existing .env, APP_KEY and storage.
2. This archive contains one top-level trader-server/ directory. To overlay an
   existing installation without creating a nested directory:

   cd /path/to/trader-server
   tar -xzf /path/to/trademinator-M4-full-20260928.tar.gz --strip-components=1

3. Install the locked PHP dependencies and apply the additive migration:

   composer install --no-interaction --prefer-dist --no-dev
   php artisan migrate --force
   php artisan optimize:clear

   Rebuild config/route caches if your deployment uses them. Compiled frontend
   assets are included. Development/test installations should omit --no-dev.

4. Add the cron-driven intelligence queue worker from docs/CRONTABS.md to the
   chosen worker host. All nodes must share their database, queue/cache and
   private research/model directories. No permanent daemon is needed.

5. Once M2 features exist, queue initial training:

   php artisan trademinator:dispatch-market-intelligence

   Or train and inspect one market using its actual selected period:

   php artisan trademinator:build-features bitso ADA/USD 1m
   php -d memory_limit=512M artisan trademinator:knn-build bitso ADA/USD 1m
   php artisan trademinator:signal bitso ADA/USD 1m

6. Open Market subscriptions and use the new Intelligence link.

Read docs/CLI.md under "M4 intelligence workflow and upgrade" for label
semantics, model validation, abstention behavior, pattern probabilities,
configuration and optional higher-timeframe derivation.

Validation: 304 PHP tests (9,049 assertions), 10 frontend tests, Laravel Pint,
route/schedule registration and a production Vite build passed locally.
No live-market predictive performance or production multi-host benchmark is
claimed. On your development/test installation, run php artisan test --compact.
The suite enforces SQLite :memory: isolation using the existing safety guards.

The archive contains source and compiled frontend assets. It contains no .env,
credentials, database, trained models, vendor/, node_modules/ or runtime caches.
Never use migrate:fresh for this upgrade. No trading orders are submitted.
