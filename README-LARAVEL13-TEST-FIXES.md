# Trademinator Laravel 13 test fixes

This overlay contains:

- `app/Domain/MarketData/CandleQuality.php`
  - Forces documented ratio/score fields to return `float`, fixing Pest strict comparison failures such as `1` vs `1.0`.
- `composer.json`
  - Adds `ext-pdo_sqlite` to `require-dev` so test environments explicitly require the SQLite PDO driver.

## Apply

From the Trademinator repository root:

```bash
# Optional backup
tar -czf pre-laravel13-test-fixes-backup.tar.gz composer.json app/Domain/MarketData/CandleQuality.php

# Extract this overlay over the repository root
tar -xzf trademinator-laravel13-test-fixes.tar.gz

composer update
```

## SQLite requirement

The tarball cannot install a PHP system extension. Confirm CLI PHP has SQLite:

```bash
php -m | grep -Ei 'pdo|sqlite'
php -r 'print_r(PDO::getAvailableDrivers());'
```

You should see `pdo_sqlite`, `sqlite3`, and `sqlite` in the available PDO drivers.

Examples:

```bash
# Debian/Ubuntu, PHP 8.4
sudo apt install php8.4-sqlite3

# Debian/Ubuntu generic package
sudo apt install php-sqlite3

# Rocky/RHEL family
sudo dnf install php-sqlite3
```

Then run:

```bash
composer update
php artisan optimize:clear
php artisan test tests/Unit/CandleQualityTest.php
php artisan test
```
