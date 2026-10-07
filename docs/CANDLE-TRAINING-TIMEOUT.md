# Action Training request timeout correction

## Scope and baseline

Incremental correction for GitHub `Trademinator/trader-server`, commit
`860ced59a806f4c7f40687bba90199f7420d8d97`. This revision already includes the
intelligence-recovery changes and the backfill lock-owner follow-up. Apply this
patch on top; do not reverse the earlier patches. No remote commit or deployment
is performed by this package.

The supplied fatal-error trace enters `CandleTrainingController::store()`,
`CandleTraining::review()`, then `unseenRow()`. The old implementation hydrates
**every labelled snapshot** in the dataset's time interval, in offset-paginated
batches of 25. Each batch verifies compatibility and may reconstruct its current
charts. Batching controls peak memory, not the total HTTP work. Additionally,
`batchModelObservations()` repeatedly fetched all earlier signals and sorted them
in PHP. The 30-second fatal occurs while this request is executing a database
query; the trace does not identify a slow SQL statement or demonstrate a Laravel
connection defect.

## Changes

1. Select candidates before loading charts. A metadata-only query obtains distinct
   recorded candle times, scoped to the requesting trainer, market, snapshot
   version and exact dataset decision times. Queries contain at most 500 decision
   values. Dataset history is limited by `INTELLIGENCE_MAX_MODEL_AGE_DAYS`,
   so the number of metadata queries grows with the eligible dataset size.
   No chart payloads or revision payloads are hydrated by those metadata queries.
2. The metadata is a prioritization hint only. Previously unrecorded times are
   tried first. `TrainingCandidateSelector` verifies at most 24 candidate candles,
   rechecks the opinion on the **resolved current snapshot**, and stops at the
   first unreviewed valid candidate. Lower `human_training.candidate_attempts`
   values are respected, but larger values cannot remove this HTTP work bound.
3. Action Training can fall back to the first verified reviewed candle when the
   bounded search has no unreviewed candidate. Outcome Training does not reassign a
   reviewed fallback. A rare changed revision among many recorded candle times
   can be outside the sample; opening its explicit decision timestamp still
   performs full validation. This is a bounded search, not a claim to classify
   every historic label on each request.
4. `CandleTraining::start()` resolves only the redirect destination. The POST no
   longer builds visible labels, milestones or exchange fee metadata that the
   subsequent GET builds again. A direct `review()` without a timestamp reuses
   its selected snapshot instead of immediately resolving it twice.
5. Historical observations use one `ORDER BY recorded_at_ms DESC,
   market_signal_id DESC LIMIT 1` query per distinct cutoff. Both the decision
   timestamp and recording timestamp must be at or before that cutoff. NULL
   decision times are excluded by the comparison. No future observation is used
   to invent a historical prediction. Market IDs are resolved once per batch.
6. A migration adds covering candidate and ordered replay lookup indexes. It does
   not modify or delete candles, opinions, snapshots, features, models or labels.

## What has not changed

Source-candle checks, feature digests, snapshot checksums, chart-input revision
identity, trainer authorization, label submission, HOLD retention, KNN validation
and backfill locking remain in effect. Changed charts do not inherit old labels.
Only selected/relevant charts are verified during selection; unrelated stored
snapshots are not proactively audited by an HTTP start request.

This patch does not change `max_execution_time`, edit `vendor/`, start builds in
HTTP, add a queue or alter a model generation key. The 24-candidate limit is a work
bound, **not an absolute wall-clock guarantee** in the presence of database or
storage stalls. Auto-label and large submission processing are separate request
paths, not globally redesigned in this correction.

## Apply

From the repository root, with the patch saved at the following path:

```sh
git apply --check /tmp/trademinator-candle-training-timeout-fix.patch &&
git apply /tmp/trademinator-candle-training-timeout-fix.patch
php artisan migrate --force
php artisan optimize:clear
```

Use the ordinary deployment process and database backup policy for the index
migration. Index creation itself may take time on a populated database. The
migration's `down()` removes only its two newly named indexes.

Restart the web PHP process after deploying (the supplied trace is from the
`php artisan serve` development server; restart it). For a PHP-FPM deployment,
reload the appropriate FPM service. A queue-worker restart alone is not a web
process restart. Restart long-lived queue workers when deploying shared PHP code:

```sh
php artisan queue:restart
```

No dependency version update or JavaScript build is required. With authoritative
Composer classmaps in a custom deployment, regenerate the autoloader as usual
because the patch adds `TrainingCandidateSelector`.

## Tests

Run in an isolated test environment:

```sh
php tests/Support/candle-training-selection-checks.php
php artisan test --filter='CandleTrainingSelection|CandleTrainingTest|CandleTrainingRecoveryTest|HumanTrainingTest|IntelligenceRecoveryTest'
php artisan test
```

The new Pest feature regressions cover a 600-candle nearly fully labelled start,
no chart preparation in the redirect, Trend selection, an all-labelled fallback,
corrected snapshot revisions, trainer isolation, missing source data, selected
snapshot corruption and historical lookups with 1,006 stored signals. The query
assertions check bounded relevant work instead of brittle elapsed-time limits.

Verification performed while preparing this patch:

- 14 standalone cases passed against the actual new PHP selector.
- 46 existing standalone recovery-domain cases passed.
- PHP syntax checks passed for the changed and added PHP files.
- SQL metadata/scoping/index-plan checks passed on SQLite with 9,002 snapshots.
- 204 historical-query equivalence cases passed with 20,005 signals on SQLite.
- Patch forward application, exact content comparison and reversal were checked
  against local files whose original blob hashes match the stated GitHub ref.

**The full Laravel/Pest suite was not run here.** The container has PHP 8.4.23 but
no application Composer dependencies, BCMath/Intl extensions or PDO database
drivers. Standalone selector tests and SQLite SQL tests are not Laravel endpoint
tests and do not measure the deployed MariaDB server's response time.
