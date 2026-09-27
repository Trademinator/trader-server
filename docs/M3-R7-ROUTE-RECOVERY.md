# M3 R7 — Market suggestions route recovery

## Reported error

`Route [markets.suggestions] not defined` means the running application's route collection does not contain the route requested by the market page.

The R6 full tarball does contain `markets.suggestions`, `markets.preferences.store`, and `markets.preferences.destroy` in `routes/web.php`. All three register and compile successfully. A stale route cache reproduces the reported exception. An incomplete file copy, a different application/release directory, or stale PHP worker OPcache can also explain why the web request sees an older route table; this release cannot inspect your server to distinguish them.

## Restore the feature

From the actual deployed application directory, using the normal application account:

```bash
php artisan route:clear
php artisan view:clear
php artisan route:list --path=markets
```

The list should include:

| Method | URI | Route name |
| --- | --- | --- |
| GET/HEAD | markets/suggestions | markets.suggestions |
| PUT | markets/preferences | markets.preferences.store |
| DELETE | markets/preferences | markets.preferences.destroy |

If these are still missing, copy the full release's `routes/web.php` and application files into the directory served by the website, then repeat those commands. Copy the *contents* of the archive's `trader-server/` directory over the application; do not accidentally create another nested installation.

If CLI lists the routes but the browser still reports the error, reload the serving PHP-FPM/Apache-FCGI workers through your hosting controls to discard old OPcache. On a multi-node deployment, update each node's source and clear/rebuild its route/view caches; run the additive database migration only as part of the normal shared-database deployment procedure.

If you normally cache routes, rebuild after verifying the updated list:

```bash
php artisan route:cache
```

Clearing these route/view caches does not reset the database, delete users or clear shared application-cache locks. Keep `.env`, `APP_KEY`, database and storage. R7 adds no migration, dependency, Artisan command, cron or collector change. If R6 has not yet been fully installed, also follow [its installation instructions](M3-R6-PAIR-SUGGESTIONS.md) for the encrypted preference table. The complete command reference is [CLI.md](CLI.md).

## Safeguard in this release

The market page checks that all three suggestion routes exist before generating the link. If any is missing, `/markets` stays available for manual subscriptions and displays a short temporary-unavailability message. This safeguard does not recreate missing routes or silently bypass authentication: restoring the suggestions feature still requires the complete source and refreshed route cache described above.

The form also tolerates an older controller that does not yet supply the new optional prefill values during an incomplete deployment.

## Verification

Regression tests reproduce compiled route collections missing each suggestion route, and an entire pre-R6 collection. They verify the market page and existing subscriptions remain usable, including unsubscribe. An isolated subprocess loads a stale route-cache file, clears it, rebuilds it and boots fresh applications to confirm all new routes and authentication return. The cache fixture lives in a private temporary directory; tests never remove deployment caches or touch a deployment database.
