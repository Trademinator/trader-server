# Trademinator cron configuration

This is the canonical list of operating-system cron entries required by Trademinator. Update this file whenever a scheduled Artisan command is added, removed, renamed, or its cadence changes, and whenever queue processing requirements change.

Trademinator is designed to run without a permanent daemon. Use a persistent queue backend such as database or Redis, but drain it from cron with `queue:work --stop-when-empty` so the worker exits naturally when the queue is empty.

## Required crontab entries

Replace `/path/to/trader-server` and `/usr/bin/php` with the deployment's actual paths.

```cron
# Laravel scheduler. This triggers all schedules defined in routes/console.php.
* * * * * cd /path/to/trader-server && /usr/bin/php artisan schedule:run >> storage/logs/scheduler-cron.log 2>&1

# Drain queued market/feed/feature work without a permanent worker daemon.
# flock prevents a new local worker from starting while the previous minute's worker is still running.
* * * * * cd /path/to/trader-server && /usr/bin/flock -n storage/framework/trademinator-queue.lock /usr/bin/php artisan queue:work --queue=default --stop-when-empty --timeout=600 --tries=5 >> storage/logs/queue-cron.log 2>&1
```

Those are the only system crontab entries currently required. Do **not** add separate cron lines for each `trademinator:*` scheduled command; Laravel's scheduler owns those cadences.

## Commands currently triggered by the Laravel scheduler

| Command | Cadence | Purpose |
| --- | --- | --- |
| `trademinator:dispatch-market-feeds` | Every minute | Queue due shared market feeds that have active subscribers. |
| `trademinator:dispatch-market-features` | Every five minutes | Queue M2 feature builds for subscribed markets with selected candle periods. |
| `trademinator:collect-market-context` | Hourly | Resolve pending subscription-driven CoinGecko mappings and collect timestamped market context. |

The schedule source of truth is `routes/console.php`; this document must be updated in the same change whenever that schedule changes.

## Queue and cache requirements

Use a persistent `QUEUE_CONNECTION` (`database` or shared Redis). `sync` and `null` are not suitable for the shared market-feed dispatcher. Set the queue connection's `retry_after` to at least **720 seconds**, which is longer than the **600-second** job timeout above.

Use a shared atomic-lock-capable cache store such as Redis when multiple application nodes run the scheduler. `onOneServer`, `withoutOverlapping`, database leases, and per-market locks protect shared work from duplicate execution. The local `flock` only prevents overlapping cron workers on the same host; the queue backend itself safely coordinates workers across hosts.

## Deployment checks

After changing application or environment configuration:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan schedule:list
php artisan queue:failed
```

Because the documented queue worker is not persistent, `php artisan queue:restart` is not required for the cron-driven worker model.
