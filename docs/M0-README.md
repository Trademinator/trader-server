# M0 — Stabilization Complete

M0 establishes the stable runtime, persistence, market-data shape, and test baseline required before M1 work is allowed to become the foundation of the trading algorithm.

## M0 acceptance baseline

- Laravel 13 / PHP 8.4-compatible application and CI configuration.
- BCMath declared and exercised by indicator tests.
- UUIDv7 model identifiers use Eloquent's `string` key type.
- Fresh test databases are UUID-native from the first users migration.
- Bulk ticker upserts generate UUIDs before the insert path and update by the natural candle key.
- One canonical associative OHLCV shape is used after CCXT ingestion.
- Numeric CCXT indexes are removed after normalization.
- Candle fetches include a candle that lands exactly on the requested end boundary.
- `1y` remains the canonical yearly timeframe spelling.
- CCI mean deviation, ATR average selection, STO period selection, and STO-RSI period selection are covered by regression tests.
- Trait helper calls use `$this->...` instead of undefined namespace functions.
- Unfinished Dragonfly/Gravestone pattern routines are explicitly quarantined.
- API-key request/model naming is consistently `api_key` / `current_api_key`.
- GitHub Actions runs Pest with SQLite in-memory while local tests remain free to use `.env.testing` MariaDB settings.

## After applying

Run:

```bash
composer install
vendor/bin/pint
composer test
npm ci
npm run build
```

M1 can proceed only after the test suite is green in the intended local MariaDB test environment and in CI.
