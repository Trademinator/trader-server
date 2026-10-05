# Two-KNN scoring and history recovery

Implementation based on `Trademinator/trader-server` commit
`dc9ec52836acec63654961df1c4f51d591c77ffa` (main, 2026-10-05).

## Deploy

Apply the patch to a clean checkout, then:

```sh
php artisan optimize:clear
php artisan queue:restart
```

No new migration, Composer dependency or frontend JavaScript asset changes are
required. Keep existing datasets, models and annotations. Sites that cache
configuration should regenerate that cache after editing `.env`. The existing
history/intelligence workers and scheduler remain unchanged.

Existing stacked models must be rebuilt for validation version `m5-two-knn-v1`;
prediction abstains on an old version. Rebuild each market using its actual
exchange, symbol, selected period and desired schema, for example:

```sh
php artisan trademinator:knn-build kraken BTC/USD 1h --schema=full
```

`full` still requires complete CoinGecko context for automatic training. Existing
technical annotation datasets can supply the human model when the automatic
dataset uses full context. The recovery action also forces a rebuild; redispatching
an already completed weekly generation does not. See [CLI.md](CLI.md#trademinatorknn-build).

## Two independent models

The automatic KNN learns cost-free future semantic outcomes. Human Candle KNN
learns submitted BUY/HOLD/SELL annotations independently. Both use the selected
technical features with identical normalization; only automatic KNN receives
CoinGecko context when selected. Existing automatic pattern/lead-lag extensions
remain automatic inputs. Human predictions are never appended to that vector.
Human Trend Training is excluded from scoring, including when its legacy flag is
true. Its annotation UI and saved reviews remain available.

```dotenv
HUMAN_TRAINING_ENABLED=true
HUMAN_TREND_TRAINING_ENABLED=false
HUMAN_CANDLE_TRAINING_ENABLED=true
INTELLIGENCE_AUTOMATIC_WEIGHT=0.40
INTELLIGENCE_HUMAN_CANDLE_WEIGHT=0.60
INTELLIGENCE_ENSEMBLE_MIN_CONFIDENCE=0.60
```

Each model must independently pass validation and live evidence gates. Supported
models contribute their full action-score distributions at the configured relative
weights (defaults 40% automatic, 60% human). An unavailable or abstaining model has
zero effective weight; remaining weights renormalize. Supported HOLD is real
evidence. Both unavailable, a tie, or insufficient combined confidence means
abstention. Confidence is the largest combined score times weighted mean similarity;
it is not a profit probability. Neighbor counts use the minimum of participating
models, never their sum, because they may describe the same candles.

Weights and thresholds are frozen into each published artifact. Changing them
requires rebuilding. Disabling Candle Training immediately removes its independent
vote; it does not prevent a supported automatic prediction. No reviews are erased.
The master switch retains its existing access behavior. The automatic model needs
no human reviews; human fitting defaults to at least 50 annotated candles and two
observed actions. Social/news scoring is not implemented and reports zero weight.
CoinGecko is automatic context, not a separately weighted social model.

Automatic training runs first. Human training has a cooperative 90-second budget
and leaves a 10-second publication reserve. Known computation-budget exceptions
skip human training. Checksum failures, invalid source data and unexpected errors
still propagate; they are not silently converted into a successful model.

## Retain HOLDs; weight votes instead of deleting examples

Both models retain every eligible distinct example within
`INTELLIGENCE_MAX_MODEL_AGE_DAYS`, which also controls model expiration. There is
no retained-example row cap. Human training checks source integrity, current trainer
authorization, consensus, annotation cutoff and horizon compatibility. Different
technical/full schemas can join after projecting the selected technical keys from
the verified original snapshot; missing technical features exclude that snapshot.

Two human class-weight policies compete during chronological tuning:

* **Natural:** no class-frequency adjustment.
* **Target priors:** training-only class weights `q_c / p_c`, default targets
  BUY 0.25, HOLD 0.50, SELL 0.25.

Each weight multiplies a real neighbor's distance-weighted vote. It does not
fabricate absent classes, duplicate rare examples, or discard HOLDs. These weights
are separate from the 40/60 model weights. The human model must pass independent
validation against human annotations; it does not have to improve or agree with
the automatic model's future labels.

The first 60% trains policy candidates, the next 20% selects the policy, and the
last 20% is a separate holdout. Outcome horizons are purged at both boundaries.
Natural weighting wins exact tuning ties. Only the selected policy is evaluated on
the holdout; a failure does not promote the runner-up. Class frequencies used for
evaluation come only from its earlier training partition. Publication then refits
that fixed policy on all eligible annotations. Annotations made today make this
retrospective research, not simulated historical live performance. Inference must
occur after the contributing annotations and source outcomes were available.

The report records `samples`, `class_counts`, `knowledge_rows`, `input_keys`,
`weight_candidates`, `weight_policy`, `class_weights`, chronology and provenance.
Human holdout precision is named `directional_annotation_agreement`, not future
return accuracy. Automatic validation stays in top-level `selection` and `holdout`;
no combined holdout performance is claimed. Signal/API `scoring` exposes individual
predictions and configured/effective weights without training vectors. Candle
Training's UI milestones count each trainer/candle once across revisions, rather
than current model eligibility; historical labels remain stored.

## Deduplication and input revisions

Before constructing KNN knowledge, the trainer audits candle identities. Exact
repeated rows at one decision timestamp are consolidated; conflicting rows at the
same timestamp fail closed. Identical feature vectors or repeated HOLD actions at
different timestamps remain distinct observations. Human Candle training selects
one latest compatible consensus snapshot per decision time. A submitted trainer opinion still has one database
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
