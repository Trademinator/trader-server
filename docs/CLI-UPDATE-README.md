# CLI reference and lowercase command update

Apply this archive at the repository root after installing M2. It contains only the CLI changes and related documentation/tests, not the whole M2 implementation.

- `CLI.md` documents all 11 Trademinator commands and identifies the KNN feature-vector files.
- Command names are now `trademinator:fetch-ohlcv` and `trademinator:create-indicators`. Update any custom scripts using the old `FetchOHLCV` or `CreateIndicators` command names. There are no mixed-case aliases. Symbols/timeframes such as `BTC/USD` and `1M` keep their original case.
- `AGENTS.md` requires updates to CLI.md whenever commands change. The new documentation test checks registered commands, lowercase names/aliases, exact signatures, descriptions, and coverage. Behavior-only changes still require manual documentation review.
- Existing specialized guides link to CLI.md.

No database migrations or new dependencies are required. Clear stale application caches and restart long-running workers after applying:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
php artisan list trademinator
php artisan test --filter=TrademinatorCliDocumentationTest
```

Validated: 2 CLI tests passed, 52 assertions, on PHP 8.4.26 with SQLite. Pint passed for the changed PHP files. No exchange or email requests were made by these tests.

The update is based on the M2 working copy delivered in this conversation. A remote refresh was blocked by automatic approval review, so newer independent repository edits were not inspected; review overlapping local changes before extracting over them.
