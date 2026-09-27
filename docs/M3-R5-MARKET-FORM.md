# M3 R5 — Remove the candle-period control

The add-market form now contains only **Exchange**, **Pair**, **Price tick size**, and **Subscribe**. The candle-period field and Automatic display are removed, and the row uses the available space without leaving an empty column. The four explanation boxes are retained, including **Candle period**, which explains that Trademinator selects it automatically based on candle quality and coverage. The expandable interval list from R4 is removed.

The collector continues to choose a candle period automatically for the shared exchange/pair feed. Its actual period remains visible beside the subscribed pair under **Your markets**. The form still checks that the exchange supports candles before enabling subscription.

## Upgrade from R3 or R4

The full R5 tarball includes all prior M3 repairs. Preserve the installation's `.env`, `APP_KEY`, database and `storage/` contents while copying application source. The application change is in `resources/views/markets/index.blade.php`.

After applying the update:

```bash
php artisan view:clear
```

Reload Apache/FCGI or PHP-FPM if OPcache continues to show the old view. R5 adds no database migration, dependency, command, schedule or collector change. Installations older than R3 should also follow [the R3 upgrade guide](M3-R3-ACCESS.md).

Verification passed: **6 PHP tests / 45 assertions**, **4 browser-script tests**, frontend build and whitespace checks. The period explanation box remains; the form control is absent.
