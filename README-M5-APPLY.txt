Trademinator M5 overlay
Base: GitHub main d3d131cd2eeefed80fedba3e5abbd1eb45f53ff5 (2026-09-30)

Apply from the trader-server repository root:

  tar -xzf trademinator-m5-overlay.tar.gz
  git apply docs/M5-M-PLAN.patch
  composer dump-autoload
  npm ci
  npm run build
  php artisan migrate --force
  php artisan optimize:clear
  php artisan config:cache
  php artisan route:list --path=api/v1/client
  php artisan test --compact
  node --test tests/Frontend/*.test.mjs

Review with:

  git status --short
  git diff --check
  git diff

M5 does not place exchange orders and does not store exchange API credentials.
Client trading and paper trading default off per market subscription.
