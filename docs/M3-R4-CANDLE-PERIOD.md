# M3 R4 — Explain automatic candle periods

Automatic period selection is intentional. Each exchange/pair has one shared market feed, used by every subscriber. When that feed first runs, the collector tries supported candidate periods from shortest to longest and stores the first one with adequate candle quality, coverage and completed history. The selected interval then appears beside the pair under **Your markets**. If no candidate qualifies, the feed stays pending and retries later.

The previous form presented **Automatic** as a dropdown with disabled exchange intervals. Those entries were informational; neither the subscription request nor the database accepted a subscriber-selected timeframe. That presentation incorrectly suggested the user could choose one.

R4 replaces the dropdown with a read-only **Automatic** display, explains shared selection, and moves the exchange's intervals into an expandable **Supported exchange intervals** reference. The reference is refreshed when the exchange changes and hidden while loading or after an error. It lists supported intervals, not a list of guaranteed quality-qualified choices. Automatic selection and stored feed periods continue to work as before.

## Upgrade an existing M3 R3 installation

The full R4 tarball includes the R3 access registry and all earlier M3 repairs. Preserve the deployment's `.env`, `APP_KEY`, database and `storage/` contents when copying application files. For this R4 interface fix, the changed application view is `resources/views/markets/index.blade.php`; compiled frontend assets are also supplied.

After copying the updated files:

```bash
php artisan view:clear
```

Reload Apache/FCGI or PHP-FPM if OPcache continues serving the old view. R4 adds no migration, Composer requirement, Artisan command, scheduler change or database operation. Do not reset the database or regenerate the application key. If upgrading from an earlier version than R3, also follow [the R3 upgrade guide](M3-R3-ACCESS.md).

## Validation

The existing market-page, subscription, error-handling and 128 MiB memory checks are used to verify the change. The existing four browser-script tests cover provider errors, non-JSON failures, an empty pair list and successful pair loading with the correct price increment. No live exchange requests are needed for this interface change.

R4 verification passed: **21 PHP tests / 186 assertions**, all **4 browser-script tests**, frontend build and whitespace checks. The PHP checks include the 128 MiB, 5,000-pair Binance fixture.
