# BuildMarketFeatures queue isolation fix

This package is based on `Trademinator/trader-server` main commit `970167f16438377f58718d44c044c32580e6ade2`.

## What changes

1. `BuildMarketFeatures` runs on a dedicated `features` queue instead of `default`.
2. The five-minute dispatcher queues a build only when the current `FeatureEngine::VERSION` is behind the newest stored candle.
3. The queued build checks again when it starts, so a duplicate/race becomes a cheap no-op.
4. Normal live feature work resumes from the existing feature checkpoint and rewrites only a three-candle overlap plus new candles, rather than replaying the complete history every time.
5. Live `CollectMarketFeed` work remains on `default`; a slow or failing feature build can no longer block candle ingestion.

No database migration is required.

## Install

Overwrite these files from the package root into the application root:

- `app/Jobs/BuildMarketFeatures.php`
- `app/Console/Commands/DispatchMarketFeatures.php`
- `config/features.php`
- optionally add `tests/Feature/BuildMarketFeaturesQueueTest.php`

Add this to `.env` (optional because `features` is also the default):

```dotenv
FEATURES_QUEUE=features
```

Refresh configuration:

```bash
php artisan optimize:clear
php artisan config:cache
```

Keep the existing live/default worker unchanged:

```cron
* * * * * cd /path/to/trader-server && /usr/bin/flock -n storage/framework/trademinator-queue.lock /usr/bin/php -d memory_limit=512M artisan queue:work --queue=default --stop-when-empty --max-time=50 --memory=384 --timeout=600 --tries=5 >> storage/logs/queue-cron.log 2>&1
```

Add a separate feature worker:

```cron
* * * * * cd /path/to/trader-server && /usr/bin/flock -n storage/framework/trademinator-features-queue.lock /usr/bin/php -d memory_limit=512M artisan queue:work --queue=features --stop-when-empty --max-time=50 --memory=384 --timeout=600 --tries=3 >> storage/logs/features-cron.log 2>&1
```

If you customize `FEATURES_QUEUE`, use the same queue name in that cron entry.

## Existing queue backlog

Jobs that were already serialized before this change keep their original queue (`default`). Since you are already waiting for the current queue to drain, deploy after it has cleared. Do not blindly run `php artisan queue:retry all` afterward: old failed `BuildMarketFeatures` payloads can still carry the old queue assignment.

## Verify

```bash
php artisan schedule:list
php artisan trademinator:dispatch-market-features
php artisan queue:work --queue=features --stop-when-empty --timeout=600 --memory=384 --tries=3
php artisan queue:failed
```

In a development checkout:

```bash
php artisan test --compact tests/Feature/BuildMarketFeaturesQueueTest.php
```
