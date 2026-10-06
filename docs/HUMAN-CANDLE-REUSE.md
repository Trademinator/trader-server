# Human candle annotation reuse after feature upgrades

## Scope

This repair was prepared against deployed revision `31c3c42a3db956f987a665a577412e49b2678bb7`. It addresses Human Candle KNN reporting zero eligible samples when recorded annotations refer to older feature versions or core-schema datasets while the current model uses a full schema.

No database migration, annotation rewrite, SQL relabeling, feature-version forgery, or automatic validation override is required. Original snapshot IDs, checksums, chart payloads, trainer identities, actions and annotation timestamps remain unchanged. The patch does not alter automatic KNN coverage, trading risk settings, validation thresholds, class-prior selection, holdout purging or the established newest-compatible-snapshot deduplication policy.

## Training behavior

1. Count recorded labels and distinct candles separately. Apply the snapshot-version, history-window, authorization and annotation-cutoff filters with explicit counts.
2. Verify each original dataset manifest and complete row checksum using the disk-backed dataset index. Check every candidate snapshot's checksum and its original market, time, schema, vector, feature checksum and horizon against its frozen row. Source corruption fails loudly.
3. For provenance-bearing annotations, verify the original reviewed OHLCV chart against canonical history, including missing or newly inserted bars. Decimal formatting differences alone are accepted; real decimal differences are not rounded away. A longer original chart remains protected when the configured chart window shrinks.
4. Read the current technical feature vector for that same candle, not the obsolete vector embedded in the original annotation. Missing selected inputs and evidence unavailable at the decision are exclusions. The human loader does not require the candle to appear in the automatic full-schema dataset or have CoinGecko context.
5. Keep one compatible opinion per candle, then run the existing chronological human KNN tuning and final holdout. Private human knowledge retains original annotation provenance plus the current feature/chart digests used in this build. These per-row details are not included in the public model report.

This remains retrospective chronological research: the original historical annotation/maturity timestamps and the post-annotation inference gate are preserved. Re-derived causal inputs are not a claim that this model existed, or could have traded, at the original historical time.

Source-less legacy research records retain their existing same-version checks. They cannot cross feature versions or acquire missing inputs through that exception. The interactive training UI's original immutable snapshot/submission checks are unchanged; this patch repairs model training and exposes its eligibility diagnostics.

## Deployment

Complete the private human-training export first. Preserve the source dataset files as well: the human-training export is not a replacement for those immutable research artifacts or a complete application/database backup.

Run commands from the deployed project directory. The example patch filename below is the delivered patch; adjust its path to its actual location. Apply to the canonical development checkout too if another synchronization/deployment process would otherwise overwrite the server's edited files.

```bash
cd /var/www/server.trademinator.com || exit 1
PATCH=/tmp/trademinator-human-candle-reuse-31c3c42.patch

sudo -u apache git apply --check "$PATCH" &&
sudo -u apache git apply "$PATCH"
```

Stop if the check fails. Do not force a patch onto a different revision or discard unrelated local edits. The check does not change project files.

Refresh the application autoloader and compiled views, then request graceful worker recycling. No configuration change or database migration is needed. Composer dependencies are unchanged; this is not a `composer update`.

```bash
sudo -u apache composer dump-autoload -o --no-scripts &&
sudo -u apache php artisan view:clear &&
sudo -u apache php artisan queue:restart
```

Long-running workers must actually exit and be restarted by their normal supervisor/cron setup before they use the patch. A worker already executing a job can finish with its previously loaded code. Coordinate with the normal deployment procedure before starting another build for the same market; do not kill active jobs or delete their locks.

## Audit before rebuilding

Use the application account because immutable research datasets are private to that account. Do not loosen the permissions of the private research directory to run the audit as another user.

```bash
sudo -u apache php -d memory_limit=512M artisan \
  trademinator:human-candle-audit bitso 'ATOM/USD' 15m
```

By default this uses the current published model's dataset to select the technical inputs and knowledge cutoff, but inspects the annotations present at audit time. To inspect a specific current-version target dataset, use `--dataset=DATASET_UUID`. `--timeout=600` raises only the audit's inspection budget; it does not change training deadlines.

The audit is read-only with respect to annotations, datasets, model records and publication. It does not mark anything ready. Its canonical history reader can populate the usual cache. It prints `validation_performed: false` deliberately.

Interpretation:

| Output | Meaning |
| --- | --- |
| `recorded_labels` / `recorded_distinct_candles` | Saved label records versus distinct candle timestamps. |
| `prefiltered_snapshots` | Sequential, non-overlapping exclusions before compatibility inspection. |
| `excluded` | Compatibility, input, evidence-availability or consensus exclusion counts among inspected snapshots. |
| `accepted_snapshots` / `duplicate_eligible_snapshots` | Compatible records before and after candle-level deduplication. |
| `projected_candles` | Eligible distinct candles using verified current technical inputs. |
| `samples` / `class_counts` | Final eligible distinct candles and BUY/HOLD/SELL counts. |

The exclusion counters count snapshots, not additional distinct candles. A missing or corrupt source artifact is an error, not an eligible label and not a reason to bypass checks. A missing input remains missing, not an invented zero.

## Rebuild and verify

After an audit completes, retrain using the intended schema. This example preserves the server's `full` schema; switching to `core` is not necessary to reuse the human annotations.

```bash
sudo -u apache php -d memory_limit=512M artisan \
  trademinator:knn-build bitso 'ATOM/USD' 15m --schema=full
```

The output includes a new `model_id`, `candle_guidance.samples`, `candle_guidance.class_counts`, `candle_guidance.annotation_diagnostics`, and the human validation result. The web intelligence page displays the saved eligibility details after the new model is published.

Enough samples do not guarantee validation: `tuning_failed`, `holdout_failed` or another abstention can be a legitimate next result. This patch does not lower the validation gates to make the market ready. Automatic KNN can independently remain unavailable while a human model is evaluated. A command's successful exit is not a claim that a model validated.

To inspect the resulting artifact, replace `MODEL_UUID` with the ID printed by the build:

```bash
sudo -u apache php artisan trademinator:model-info MODEL_UUID
sudo -u apache php artisan trademinator:signal bitso 'ATOM/USD' 15m
```

`no_post_training_candle` or `no_post_annotation_candle` can require a subsequent closed candle before inference can use a new model.

## Tests

The added tests use SQLite `:memory:` and synthetic private artifacts. They cover old core schemas, three feature versions, retirement of old feature-table rows, sparse full-schema datasets, current-vector training/inference, original snapshot preservation, exact decimal chart identity, changed/missing/filled chart bars, reconstruction evidence, authorization, annotation cutoff, checksum corruption, original-vector verification, duplicate provenance, and command argument validation.

```bash
php vendor/bin/pest tests/Feature/HumanCandleProjectionTest.php \
  tests/Feature/HumanCandleKnnTest.php \
  tests/Feature/TrademinatorCliDocumentationTest.php --compact
```

Run tests only using the repository's protected test bootstrap. Never reset or migrate the production database to run tests.

## Rollback

A reverse patch reverts code only. It does not unpublish or erase any model created after deployment, and it must not erase annotations. Restore the previous application release using the normal deployment process and separately review model publication if a model was built with this patch. Do not restore an old whole database over annotations created since the backup.
