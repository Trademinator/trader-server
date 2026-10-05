# Optional human guidance and history recovery

Implementation based on `Trademinator/trader-server` commit
`e2cd2f506ebae77938347aea34e46c2db74ebf35` (main, 2026-10-04).

## Deploy

Back up the database together with `storage/app/private/research` and
`storage/app/private/intelligence`. Apply the patch to a clean checkout, then:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

No Composer dependency or frontend JavaScript asset changes are required. This is
an additive migration; do not run `migrate:fresh`, replace APP_KEY, or delete old
research/model artifacts. Deploy the migration before restarting workers. Sites
that normally cache configuration should regenerate that cache after editing
`.env`. Keep the existing history and intelligence workers and minute-by-minute
`trademinator:backfill-ohlcv` scheduler enabled. No new cron entry is added.

## Independent, optional enhancements

The existing master switch remains supported. Add the following to `.env` to
turn off the Trend enhancement while retaining the Candle enhancement:

```dotenv
HUMAN_TRAINING_ENABLED=true
HUMAN_TREND_TRAINING_ENABLED=false
HUMAN_CANDLE_TRAINING_ENABLED=true
```

The two new flags default to true to preserve existing installations. Either or
both can be disabled independently. These flags control model enhancement; they
do not erase saved reviews. The master switch retains its existing access behavior.
The base model needs no human reviews. The 50-opinion threshold is an optional
auxiliary fitting requirement, not a market-readiness prerequisite.

Ordinary model training runs first. Optional comparisons get a bounded computation
budget (90 seconds each by default) and leave a 10-second publication reserve.
Known auxiliary/KNN computation-budget exceptions skip the optional enhancement.
Checksum failures, invalid source data, and unexpected programming errors still
propagate; they are not silently converted into a successful model. These are
cooperative budgets, not preemptive interruption of database or library calls.

Published artifacts remain immutable. Turning off a feature already included in
a combined model makes that model abstain until rebuilt; removing vector dimensions
from a trained artifact would be invalid. Existing v2 Candle artifacts remain
readable without changing their original inference behavior. New builds use v3.
Use the recovery action or a direct `trademinator:knn-build` to adopt new settings;
redispatching an already completed weekly generation does not force its rebuild.

## Retain HOLDs; weight votes instead of deleting examples

Candle guidance retains every eligible distinct labelled candle in its selected
training prefix. Existing dataset bounds, source checks, annotation cutoff,
authorized reviewers, consensus, horizon purge and chronological partitions stay
in effect. This does not load every historical label without a window limit.

Two policies compete during chronological tuning:

* **Natural:** no class-frequency adjustment.
* **Target priors:** training-only class weights `q_c / p_c`, default targets
  BUY 0.25, HOLD 0.50, SELL 0.25.

Each weight multiplies a real neighbour's distance-weighted vote (implemented by
multiplying its class's summed vote share, then normalizing). It neither duplicates
rare examples nor fabricates evidence for a missing class. A HOLD-only neighbourhood
remains HOLD-only. These weights are not a required percentage of labels or signals,
and opinion shares are not calibrated price or profit probabilities.

There must be enough actual opinions (default 50) and at least two observed actions
to attempt fitting. A rare or absent third action does not cause equal-count
undersampling. The model must still satisfy the ordinary validation gates and
improve on the baseline before it influences production intelligence.

Policies are selected using tuning results only, with natural weighting winning an
exact tie. Only the selected policy is evaluated on the final, naturally distributed
holdout. A failed holdout does not trigger testing/promoting the runner-up. Neither
holdout labels nor future annotations determine class frequencies or weights.

The report records `training_samples`, `training_class_counts`, `weight_candidates`,
selected `weight_policy` and `class_weights`, plus existing comparison metrics.
The old `balanced_class_counts` report field is replaced by actual retained counts.
Candle Training's displayed milestones count each trainer/candle once across stored
revisions, not current model eligibility or a balancing quota. The model report
shows actual compatible training counts; historical labels remain stored.

## Deduplication and input revisions

Before constructing KNN knowledge, the trainer audits candle identities. Exact
repeated rows at one decision timestamp are consolidated; conflicting rows at the
same timestamp fail closed. Identical feature vectors or repeated HOLD actions at
different timestamps remain distinct observations. Candle opinions receive the
same audit after consensus. A submitted trainer opinion still has one database
record per snapshot/trainer. Auto-label consecutive-action cleanup is unchanged;
it is not applied as a blanket rewrite of manually submitted labels.

Snapshot compatibility checks stream stored charts in batches of 25; new snapshot
payloads are constructed in batches of 50. Milestone counts remain database-side
aggregations and do not load historical charts into memory.

New snapshot identity hashes the reviewed inputs: chart, feature schema/version,
vector, source digest, horizon and partial-pattern metadata. Later model observations
and revision metadata are excluded from this identity. The independent full-snapshot
checksum still covers the entire stored payload.

Unchanged legacy snapshots are reused, preserving their labels. Changed inputs
create a new immutable snapshot with a predecessor reference and review-required
metadata. No label is copied onto changed inputs. Superseded snapshots and labels
remain available as historical records. A single-candle deletion targets only the
current snapshot; the explicit existing Delete all training action still deletes
that trainer's labels for the market, including historical revisions.

Real provenance-bearing snapshots are checked against current source features and
chart inputs before being used/displayed in Candle Training. Source-less legacy
research records retain their prior feature/schema compatibility checks until they
have a known history revision; they are not represented as newly verified source
history. Corrupt snapshot checksums remain fatal.

A repaired chart cannot be combined with a stale dataset feature digest. Open a
fresh dataset after recovery. The previous 422 recovery guidance remains in place
when the old dataset is genuinely incompatible; input revisions are not a bypass
for source validation.

## Repair history and rebuild

On the market intelligence readiness panel, the owner has a **Repair history and
rebuild** link. It opens:

```text
/owner/history-recovery/{market_uuid}
```

The POST action requires an authenticated, verified server owner, CSRF protection,
recent password confirmation and request throttling. Duplicate recent requests are
coalesced under a database row lock. Ordinary users cannot access this operation.
The model's current core/technical/full schema is retained for the requested rebuild.
Custom-schema models are not silently replaced: use their original CLI workflow.

Recovery scans verified hot+cold history using the existing repository reader,
queues bounded exchange repair pages, and uses the existing feature/model pipeline.
No exchange requests or model training run inside the web request. The page shows
tracked source intervals, feature replay stage, history/trained revisions, dataset
replacement, unreviewed snapshot revisions, and the last model validation separately.
Refresh to see updated state; no background browser polling is added.

A successful source insertion records the exact minimum/maximum recovered timestamps
in a transactionally versioned change ledger. Existing closed candles returned as
padding are not silently overwritten by a missing-candle repair. The first dirty
change starts a fixed 30-second debounce, picked up by the existing minute scheduler;
subsequent pages do not continually extend that deadline.

If every revision since the last trained revision has known bounds, feature replay
starts from the earliest actual change using a checkpoint strictly before it. It
replays forward because recursive indicators depend on earlier state. Dataset
rebuilding recomputes outcome windows as well; it is not limited to inserting a
single feature row at the missing timestamp.

Legacy imports/backfills without complete range metadata and explicit rebuilds with
no known changed interval use the full hot+cold history replay. Existing split-job
resumption is retained. A newer revision invalidates an older split chain's lease;
its remaining children cannot publish an obsolete revision as current. Repair and
replay share a per-market history lock; the KNN publication stage also holds the
feature lock. Only the completed revision becomes `trained_revision`.

The repair action can explicitly retry paused/unavailable intervals after the owner
fixes access or restores history. It does not automatically loop forever on those
states. A successful response with no candle is not proof of a zero-trade period.
Existing failure/retry reason codes remain compatible. Verified same-exchange archive
imports/restoration use the existing archive tools; no third-party price substitution,
trade reconstruction provider, or synthetic flat candles are introduced.

Kraken's official OHLC documentation limits the endpoint to its most recent 720
entries and states that older entries cannot be recovered through `since`:
https://docs.kraken.com/api-reference/market-data/get-ohlc-data
The UI explains this limitation; the code does not guess a retention timestamp by
subtracting 720 candle periods, because sparse entries need not be consecutive.

A market with no stored candles needs the normal collector first. A completed repair
may still produce an abstaining model. The report, not the successful job exit code,
is authoritative for validation. Missing human reviews never substitute for failed
model validation or missing source candles.

## CLI and verification

Existing CLI commands remain unchanged:

```sh
php artisan trademinator:candle-gaps
php artisan trademinator:backfill-ohlcv --status
php artisan queue:failed
# Use the actual feed identity and preserve its schema:
php -d memory_limit=512M artisan trademinator:knn-build EXCHANGE SYMBOL PERIOD --schema=SCHEMA
```

Run the regression checks in the application environment:

```sh
php tests/Support/intelligence-recovery-checks.php
php artisan test --filter='IntelligenceRecovery|CandleGuidance|HumanGuidance|HumanTraining|CandleTraining|CandleGapRepair|Backfill'
php artisan test
```

The standalone script exercises 46 domain cases and requires PHP only. New feature
tests cover optional baseline training, actual HOLD retention, holdout independence,
revision range accounting, snapshot reuse/replacement/checksum failure, queue
idempotence, and owner authorization. Application integration tests require the
project's locked Composer dependencies and its PHP extensions.
