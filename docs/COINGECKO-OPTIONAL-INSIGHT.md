# Optional CoinGecko insight and renamed intelligence profiles

New definitions (no legacy CoinGecko-in-KNN schema):

| Profile | KNN input vector | Optional auxiliary insight |
| --- | --- | --- |
| core | 15 OHLCV-based features | none |
| technical | 18 OHLCV-based features | none |
| enhanced | same 15 features as Core | CoinGecko Random Forest |
| full | same 18 features as Technical | CoinGecko Random Forest |

CoinGecko's nine M2 context values are still stored independently. None are
required in a baseline KNN vector. The Random Forest is an optional advisor
trained against the same future five-class Outcome labels and horizon H.
Its prediction is not a replacement for the existing automatic/human fusion.

**Migration:** Prior 27-feature Full model artifacts and frozen datasets cannot be
reinterpreted; they must be rebuilt. Model and schema versions are bumped.
Existing OHLCV, reconstructed candles, CoinGecko observations and human annotations
are preserved. Prior 27-vector artifacts may be kept as archives but cannot be
used for new inference or training under the redefined Full profile.

Use `INTELLIGENCE_SCHEMA=enhanced` or `full` for the advisor profiles.
`INTELLIGENCE_COINGECKO_INSIGHT_ENABLED=true` enables optional forest training.
`INTELLIGENCE_COINGECKO_INFLUENCE_ENABLED=false` keeps the optional advisor
in observation-only (shadow) mode until paired holdout validation.

CoinGecko collection remains independently configured via `COINGECKO_ENABLED`
and `COINGECKO_API_KEY`. This update does not increase polling frequency.
The advisor uses available independent snapshots, excluding price deviation
until timestamp alignment is fixed. Stale/missing context never prevents base
KNN training. Standalone validation requires a purged chronological holdout.

The optional forest is serialized to its own checksum-verified sidecar.
Default fusion weight is zero; even with explicit influence enabled it cannot
turn an original HOLD into a trade and is capped at 15%.

## Automatic KNN feature-history fallback

By default the scheduled baseline is `INTELLIGENCE_SCHEMA=full`.
`INTELLIGENCE_TECHNICAL_FALLBACK=true` runs a **read-only pre-build
capacity check** of complete, timely features. It selects the frozen feature
schema **once, before labels/model fitting or holdout validation**:

- Technical -> Core when the long-horizon returns cannot support tuning and holdout.
- Full -> Enhanced for the same missing-return-history reason, preserving the
  optional CoinGecko advisor.
- Core/Enhanced stay unchanged.

Never retry the weaker schema because the stronger KNN's holdout failed,
or because source data, feature contracts, locks, memory or time budgets
have errors. Selection metrics are recorded with the model.
A scheduled check without enough either way skips without publishing a new head.
