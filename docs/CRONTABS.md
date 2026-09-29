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

Those two entries cover collection and M2 features. M4 training also requires the intelligence queue worker below. Do **not** add separate cron lines for each `trademinator:*` scheduled command; Laravel's scheduler owns those cadences.

## Commands currently triggered by the Laravel scheduler

| Command | Cadence | Purpose |
| --- | --- | --- |
| `trademinator:dispatch-market-feeds` | Every minute | Queue due shared market feeds that have active subscribers. |
| `trademinator:dispatch-market-features` | Every five minutes | Queue M2 feature builds for subscribed markets with selected candle periods. |
| `trademinator:collect-market-context` | Hourly | Resolve pending subscription-driven CoinGecko mappings and collect timestamped market context. |
| `trademinator:dispatch-market-intelligence` | Monday at 04:00, application timezone | Queue one KNN/pattern training job per subscribed market and selected period. |
| `trademinator:refresh-exchanges` | Daily at 03:20, application timezone | Inspect installed CCXT source, refresh access classifications and add missing exchange rows while preserving existing data. |

The schedule source of truth is `routes/console.php`; this document must be updated in the same change whenever that schedule changes.

## Queue and cache requirements

Use a persistent `QUEUE_CONNECTION` (`database` or shared Redis). `sync` and `null` are not suitable for the shared market-feed dispatcher. Set the queue connection's `retry_after` to at least **720 seconds**, which is longer than the **600-second** job timeout above.

Use a shared atomic-lock-capable cache store such as Redis when multiple application nodes run the scheduler. `onOneServer`, `withoutOverlapping`, database leases, and per-market locks protect shared work from duplicate execution. The R3 exchange refresh runs on **every node** because each node owns its installed CCXT files and runtime snapshot; the command uses its own local file lock and a shared cache lock for database inserts. It is offline and does not update the CCXT package or approve changed adapters. Composer install/update also rebuilds local metadata without database access. No additional system cron entry is needed.

The local `flock` only prevents overlapping cron workers on the same host; the queue backend itself safely coordinates workers across hosts.

## Deployment checks

After changing application or environment configuration:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan schedule:list
php artisan queue:failed
```

Because the documented queue worker is not persistent, `php artisan queue:restart` is not required for the cron-driven worker model.

## M3 research commands

`trademinator:build-dataset`, `trademinator:dataset-info`, and `trademinator:backtest` run explicitly on demand. The original M3 research implementation adds no schedules, jobs, worker timeouts or required system cron entries. M3 R3 adds the daily exchange refresh above through the existing scheduler. The two entries above remain sufficient. Each dataset/backtest invocation creates a new immutable experiment, so automatic repetition would consume storage. See [CLI.md](CLI.md) and [M3-README.md](M3-README.md) for the workflow.

The canonical filename is `docs/CRONTABS.md`. This corrects the previous GitHub filename `docs/contabs.md` and supersedes references to the earlier `docs/contact.md`.


## M4 intelligence workers

M4 adds a dedicated CPU-heavy queue. Keep the scheduler on one or multiple scheduler nodes and drain `intelligence` on a worker machine with the same source, database, shared cache and shared private model/research storage. Web nodes do not need to drain this queue. No permanent daemon is required.

```cron
# Add on each chosen intelligence worker, alongside or instead of that node's default-queue worker.
* * * * * cd /path/to/trader-server && /usr/bin/flock -n /tmp/trademinator-intelligence-queue.lock /usr/bin/php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --timeout=600 --memory=512 --tries=3 >> storage/logs/intelligence-cron.log 2>&1
```

The `/tmp` lock is local to the worker host; use a distinct lock filename for separate installations. Multiple worker hosts may drain the shared queue. `INTELLIGENCE_QUEUE` defaults to `intelligence`; if changed, change the worker's `--queue` to match. Database/Redis `retry_after` must be at least 720 seconds, greater than the 600-second job timeout. Worker PHP needs the same Composer extensions as the application. Memory needs depend on schema/history; 512 MiB is a starting limit, not a production benchmark.

Weekly dispatch runs Monday at 04:00 in the application timezone using shared scheduler locks. Per-market uniqueness/build locks and the database's unique weekly generation key also protect redelivery after a worker crash. Retries use 300/900-second backoff. Completed generations include abstaining models; failed input/history builds remain visible in normal failed-job reporting. Training snapshots/models are retained for audit and need ordinary backup/storage capacity planning.

Set `INTELLIGENCE_ENABLED=false` to disable weekly dispatch. To populate initial models, after M2 features exist, run:

```bash
php artisan trademinator:dispatch-market-intelligence
php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --timeout=600 --memory=512 --tries=3
php artisan queue:failed
php artisan schedule:list
```

M4 does not change the default queue timeout or M1/M2 collection cadences. `derive-timeframe`, direct `knn-build`, `model-info` and `signal` are on-demand commands. Longer derived timeframes are not automatically retrained by the base-period dispatcher. See [the complete CLI workflow and model contracts](CLI.md#m4-intelligence-workflow-and-upgrade).
