Trademinator — owner administration, syslog and local GeoIP

Source: GitHub main f09e402f9977453065c9215852ba5a38661d9325 (M4.1).

1. Preserve your existing .env, APP_KEY, database, private research/models,
   and writable storage. Overlay this archive from the application directory:

   tar -xzf /path/to/trademinator-owner-syslog-geoip-full-20260929.tar.gz --strip-components=1

2. Install locked dependencies and run the additive migrations:

   composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan optimize:clear

   Development/test installations should omit --no-dev. Never use migrate:fresh.

3. Set OWNER_UUID in .env to your existing users.user_id, not your API key.
   The account must sign in normally and verify its email. Configure the
   local City MMDB and proxy settings as described in docs/CLI.md:

   OWNER_UUID=your-existing-user-uuid
   ACTION_SYSLOG_ENABLED=true
   ACTION_SYSLOG_IDENT=trademinator
   ACTION_SYSLOG_FACILITY=local0
   GEOIP_DATABASE_PATH=/usr/share/GeoIP/GeoLite2-City.mmdb
   ACCESS_STATISTICS_ENABLED=true
   ACCESS_STATISTICS_RETENTION_DAYS=90
   TRUSTED_PROXIES=

   Supply a licensed GeoLite2 City or GeoIP2 City MMDB at the configured path.
   The package includes its PHP reader, not the production database. Lookups
   are local; no visitor IP is sent to an external service. Missing/unmapped
   locations appear as Unknown. List only actual trusted proxy IPs/CIDRs.

4. Rebuild configuration and views:

   php artisan config:cache
   php artisan view:cache
   php artisan schedule:list

   Let existing cron workers finish and restart with the updated code/config.
   Keep your existing scheduler and queue crons. Retention cleanup is now
   scheduled at 02:40 daily; no additional application daemon is needed.

5. Open /owner or Server administration in the owner's sidebar. It includes
   user controls, global subscriptions, intelligence reports and city/country
   access statistics. Counts begin after deployment. Suspension stops the
   user's subscriptions; restoring an account leaves them inactive.

6. Check syslog on every web/worker host:

   php artisan list trademinator
   journalctl -t trademinator --since "5 minutes ago" -o cat
   journalctl -t trademinator -f -o cat

   The host must expose its syslog socket to PHP. The one-line JSON records
   contain trace IDs, operation labels, safe identifiers, counts and outcomes.
   They omit credentials, emails, IPs, request values and exception messages.
   Commands/jobs have started and completed events; HTTP responses contain
   X-Trademinator-Trace for investigation.

Full setup, behavior, limits and GeoIP updating: docs/CLI.md under
"Owner administration, syslog and local GeoIP". Schedules: docs/CRONTABS.md.
No model rebuild is required specifically for this update.

Validation: 404 PHP tests (9,768 assertions), 10 frontend tests, Laravel Pint,
Composer validation/platform checks, Blade compilation and Vite production
build passed. PHP started with a 128 MiB limit; the existing PHPUnit 512 MiB
budget remains in effect. Test databases were SQLite :memory: only.
Host journald, production MariaDB and real GeoIP data require deployment checks.

Source and compiled frontend assets are included. .env, databases, production
MMDB files, runtime data, vendor/ and node_modules/ are not included.
