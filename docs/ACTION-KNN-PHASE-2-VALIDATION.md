# Phase 2: evidence-aware Action KNN validation

Base: GitHub `Trademinator/trader-server` `7841b16b0f449ab745bf368e46cc2bad6c484a07`
(Phase 1 already applied). This is a **Server** research/readiness change, not a
Client execution or provisional-candle relabelling feature.

## Contract

* **Natural label frequencies are untouched** in tuning and final chronological
  holdout. Neither Action source is oversampled or artificially balanced. Human
  candidate class-prior vote weights remain trained on the earlier partition only.
* **One 20% final chronological holdout stays sealed.** K and human class-prior
  policy selection use earlier tuning only; training rows whose labels were not
  available by a test cutoff are excluded. There is no second chance on holdout.
* **Three outcomes**: `validated` (all gates pass), `insufficient_evidence`
  (historical sample, class diversity, opportunities or directional predictions
  too few), `failed` (enough evidence exists but prediction quality fails).
  A nonvalidated Action source remains abstaining. Operational reasons use
  `insufficient_directional_evidence` or `holdout_failed` (quality failure).
* **No directional-coverage quota.** `coverage` is reported, not a readiness
  requirement. A rare but supported trade signal is not rejected solely for
  making up less than 1% of the candles. At least five historical directional
  opportunities *and* five supported directional predictions are still required
  by default; the human Action policy uses three of each.
* **Quality requires** directional precision >= 55%, Wilson 95% lower
  confidence bound >= max(55%, natural prediction-mix baseline + 2 percentage
  points), and opposite-pivot contradictions <= 5%. Statistical bounds are
  diagnostics of historical classification agreement, **not expected profit**.
* **Per BUY/SELL audit** includes natural prevalence, actual/predicted counts,
  precision, 95% Wilson interval, recall, false positives, false-positive
  rate, missed opportunities and abstentions. Supported HOLD and abstention
  remain separate. Undefined rates are `null`, never misleading zeros.
* **Soft-closed candle** is never assigned a historical label. Server inference
  continues to state the true `completed_candle` input basis. Client API still
  maps Server abstention to `hold` while preserving its reason.
* **Outcome KNN unchanged.** Action KNN and Human Action KNN share the same
  evidence-aware evaluator, with separate Human Action minimum counts.

## Deployment / versioning

`IntelligenceTrainer::VERSION` increments from v5 to v6; HumanCandleKnn v1 to
v2. Old model heads are ineligible for new inference until rebuilt. Historical
rows and human annotations remain untouched. No migration or new dependency.

```bash
php artisan optimize:clear
php artisan queue:restart
php -d memory_limit=512M artisan test tests/Unit/ActionKnnEvidenceValidationTest.php \
  tests/Unit/ActionKnnHoldoutAccountingTest.php tests/Unit/KnnTunerTest.php \
  tests/Feature/HumanCandleKnnTest.php tests/Feature/IntelligenceWorkflowTest.php
# Rebuild each followed market on its currently selected period and schema:
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=full
```

The read-only `trademinator:analyze-validation-gates` legacy command still uses
old model-report layout assumptions and is **not** a Phase-2 readiness oracle;
repairing that command is tracked for Phase 3.
