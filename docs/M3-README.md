# M3 — Labels, frozen datasets and walk-forward backtesting

## M3 final interface update — 2026-09-28

- Market subscriptions have one card per exchange, with a shared logo/header and divided market rows.
- Exchange headers use Bootstrap Collapse. Groups start closed, show their market count, and open independently using the mouse or keyboard. Reduced-motion preferences are respected.
- Every active or inactive subscription links to its pair review. Current shortlist matches retain their detailed preference explanation. Owned subscriptions without matching preferences or outside the shortlist show a technical review with stored closed candles instead, without claiming a current preference match or changing saved answers.
- Chart refresh stops and requests a page reload if the review changes between a preference assessment and a technical review.
- CCXT 4.5.57 provides `urls['logo']`, not a separate standard square-icon field. The exchange header preserves the logo's proportions in an 88 × 40 transparent area with no border or background square; unavailable images fall back to the exchange initial. A background embedded in an exchange's image remains part of that source image.
- This full source archive includes the production frontend build and the earlier BCMath fixes. Bootstrap 5.3.8 is added for its Collapse JavaScript component, with scoped transition styles that preserve the existing Tailwind design. It adds no database migration, scheduler or queue change. `docs/CRONTABS.md` remains the canonical cron guide.

For an existing installation, extract the archive into a staging directory and copy its `trader-server/` contents over the application while preserving `.env`, `storage/`, the database and installed dependencies. Run `composer install --no-dev --prefer-dist --optimize-autoloader` and `php artisan view:clear`. The included `public/build/` is ready to deploy; use `npm ci && npm run build` only when rebuilding assets yourself.

The requested M3 interface work is complete in this release. M4 model training and prediction remain separate.

**M3 R2 fixes pair loading and catalogue memory use.** For an existing M3/R1 deployment, follow [M3-R2-MARKETS.md](M3-R2-MARKETS.md); the repair adds no migration and preserves R1's database safeguards.

**M3 R1 corrects an unsafe test configuration in the original M3 package.** Install the complete R1 safety files before running tests. If users/exchanges disappeared after installation, start with [M3-R1-RECOVERY.md](M3-R1-RECOVERY.md). Normal M3 migrations do not reset application data.

Based on GitHub `Trademinator/trader-server`, commit `9140440c` (see `RELEASE.json` for the full source revision). This milestone adds the evaluation foundation before M4's Rubix KNN training. No orders are sent and no trained predictor is claimed.

## Install the full source archive

The archive contains the complete project source under `trader-server/`, including M0–M2, branding, M3, migrations, tests and documentation. Composer/npm lockfiles are unchanged. Dependencies, `.git`, local `.env`, databases, research data and runtime caches/logs are excluded. Compiled frontend assets are included when listed in `RELEASE.json`.

Extract to a new directory or review and merge into your existing deployment. Preserve the existing `.env`, `APP_KEY`, database and `storage/` contents. Do not replace those with a new application's files.

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan schedule:list
```

For a new installation, configure `.env` and generate its application key first. If rebuilding frontend assets, use `npm ci && npm run build`. Ensure the application/CLI user can write `storage/` and `bootstrap/cache/`. Keep the two existing system cron entries in [CRONTABS.md](CRONTABS.md); M3 requires no additional cron entry, queued job or permanent daemon.

## Run M3

Use the exchange, pair and period you already collect. Sync missing history with M1 if necessary, then rebuild M2 features before taking a snapshot, especially after historical candle corrections.

```bash
php artisan trademinator:build-features kraken BTC/USD 1m
php artisan trademinator:build-dataset kraken BTC/USD 1m --schema=core --horizon=12 --fee-bps=10 --slippage-bps=5 --min-return-bps=10
```

Copy `dataset_id` from the output:

```bash
php artisan trademinator:dataset-info DATASET_UUID
php artisan trademinator:backtest DATASET_UUID --strategy=majority --train=500 --test=100
php artisan trademinator:backtest DATASET_UUID --strategy=trend --train=500 --test=100
```

`DATASET_UUID` is a placeholder. The commands print JSON including the manifest or backtest report path. The first valid test block needs at least the requested training count plus enough intervening rows for all training outcomes to mature. With short history, reduce `--train` explicitly; a command never silently reduces your requested minimum. The default costs above are assumptions, not fetched exchange fees; set them for your account and market.

Full signatures, every option/default, side effects and examples are maintained in [CLI.md](CLI.md).

## Label definition

Version: `m3-next-open-v1`. A feature is observable at the close of its signal candle. Entry uses the **next candle's open**; with horizon H, exit uses the **H-th subsequent candle's close**. H=1 therefore measures the next candle's open-to-close move. All intervening candles must exist and be complete by the recorded as-of time. Gaps, missing raw source candles and immature outcomes are excluded and counted.

Let `E` be entry price, `X` exit price, `f = fee_bps / 10000`, `s = slippage_bps / 10000`, and `m = min_return_bps / 10000`:

```text
cost_factor = (1 - f)^2 * (1 - s) / (1 + s)
buy_net_return = (X / E) * cost_factor - 1
sell_base_net_return = (E / X) * cost_factor - 1
```

- BUY if `buy_net_return > m`.
- SELL if `sell_base_net_return > m`.
- HODL otherwise, including exact equality and flat fee-free prices.

SELL describes selling existing base units and repurchasing later, measured relative to continuing to hold those base units. It is a bearish classification target; it is not a short-sale return in quote currency. The spot-only portfolio evaluation enters from cash on BUY and remains in cash on SELL/HODL. Both cost formulas include fees and adverse slippage on each side. Prices are kept as source decimal strings in snapshots; calculations use finite PHP floats for research, not order execution.

## Dataset and feature contract

Each build receives a new UUID and records one market, period, feature version, ordered schema and label definition. Rows are read under one database snapshot (REPEATABLE READ for MariaDB/MySQL); new datasets never overwrite previous experiments. Dataset builds share M2's 720-second market/period cache lock so a snapshot cannot capture a feature rebuild in progress, and stop after 540 seconds. The stream uses bounded pages and a horizon window. A failed build cleans its files and database record; a process forcibly killed during publication can leave an unregistered directory, which no command will load as a dataset.

Files are private under `storage/app/private/research/<dataset-id>/`:

- `manifest.json`: feature keys/order, schema/version, fixed normalization, label formula version/costs, effective decision/as-of boundaries, row/class counts, exclusions and row-file SHA-256.
- `rows.jsonl`: one observation per line with ordered `vector`, separate `label`, signal/entry/outcome times, frozen entry/exit prices and returns, source feature ID/history origin/context ID, plus feature and candle-window fingerprints.
- `backtest-<run-id>.json`: complete report for each evaluation invocation.

`research_datasets` stores manifests; `research_backtests` stores reports linked by UUID. Keep these tables and the private files together in backups. On multiple nodes, run research on the node owning these files or configure a shared private filesystem via `config/research.php`. No public endpoint exposes datasets, reports or algorithm inputs.

Schema choices:

| Schema | Selected features | Missing-value policy |
| --- | --- | --- |
| `core` (default) | 15 technical features excluding 24h/7d/30d returns | Drop a row only if a selected feature is missing. |
| `technical` | All 18 M2 technical features | Long elapsed returns require their actual continuous history. |
| `full` | All 18 technical and 10 CoinGecko features | Requires every selected point-in-time value, including optional supply/category metrics. |
| `custom` | Explicit ordered `--features` names | Useful for a chosen technical/context mix; unknown or duplicate keys fail. |

No null-to-zero conversion, fitted imputation, full-history scaler or future-derived feature selection is applied. M2's fixed versioned normalization is retained. Floating-point roundoff within `1e-12` of a continuous feature's 0/1 boundary is clipped to that boundary; larger violations fail. This tolerance is recorded in the manifest. A full vector may remain unavailable for an uncapped asset or missing category; use an explicit custom schema if that feature is inappropriate. CoinGecko context remains point-in-time as provided by M2 and cannot be backfilled using today's values. `--as-of` restricts label availability; it does not undo exchange revisions or reconstruct an earlier database state. Dataset snapshots preserve the values read at build time.

`DatasetSnapshotBuilder.php` assembles the frozen labelled rows. `DatasetStore.php` verifies checksums/counts/ordering before loading. Raw future prices and labels are separate from `vector`; M4 must feed only the manifest's selected feature columns into a model. Source fingerprints support auditing; replaying a snapshot does not require the original raw database rows to remain present. Reconstructing every original indicator calculation would additionally require retaining the underlying candle/context history.

## Walk-forward evaluation

`WalkForward.php` yields chronological, nonoverlapping test blocks with an expanding or rolling training history. There is no random split. Before each fold, rows whose label endpoint is **at or after** the first test decision are purged from training. `--gap` optionally excludes additional immediately preceding dataset rows; it cannot disable this purge. Earlier test rows may enter a later fold's training only after their outcomes are observable. This separates **when a feature is known** from **when its target is known**.

M3 baselines are majority-of-mature-training-labels, sign of M2 EMA trend, always-BUY and always-HODL. Majority ties prefer HODL, then BUY, then SELL. The report includes fold boundaries, purged counts, eligible training class counts, per-row predictions, actual labels, accuracy, the confusion matrix, per-class precision/recall/F1 and macro F1 averaged over all three classes. Unsupported/absent classes receive zero precision/recall/F1, rather than an undefined number.

The portfolio starts with one unit of quote-currency cash, invests all cash on BUY and exits at its frozen horizon. Entry signals while a trade is open are skipped across fold boundaries, preventing overlapping horizons from reusing the same capital. Equity/returns include both entry and exit costs. A comparable always-BUY fixed-horizon benchmark uses the same test rows and execution assumptions. It is not a continuous buy-and-hold benchmark.

Drawdown is sampled at realized exits, so it does **not** estimate intratrade drawdown. OHLCV lacks historical spread, queue position, market impact, outages and actual fill latency; slippage is a configurable approximation. Adjacent classification outcomes can overlap in time and are correlated even though executed trades do not overlap. Keep a final untouched time interval when comparing strategies or tuning M4; repeatedly selecting against these same test blocks makes them development data.

The default build/load limit is 50,000 eligible rows (`config/research.php`), and evaluations allow at most 1,000 folds. Over-limit requests fail rather than truncate. Backtests load the selected snapshot into memory; choose a smaller `--from`/`--to` range on constrained hosts. M3 does not add incremental model checkpoints, K optimization, training jobs or a model registry; those belong to M4.

## Verification

Use a development checkout with M3 R1 fully installed. The standard suite now forces SQLite `:memory:`, bypasses deployment configuration caches and fails before database refresh if resolved database settings are unsafe. `pdo_sqlite` is required; a persistent application database must never be used by the test suite.

```bash
php artisan test --filter='Research|WalkForward'
php artisan test --filter=TrademinatorCliDocumentationTest
php artisan test
```

Coverage includes costs and threshold boundaries, next-open timing, unfinished/gapped horizons, calendar periods, explicit context feature selection, source-independent immutable snapshots, tamper detection, failed-build cleanup, strict purging, causal majority fitting, rolling/expanding folds, and nonoverlapping portfolio trades. See `RELEASE.json` for validation performed for this archive.

## M3 R6: optional pair suggestions

The market page now links to a private preference questionnaire and explained candidate shortlist. This uses explicit suitability rules and bounded stored-candle evidence; it does not add a trained predictor or claim future profitability. See [M3-R6-PAIR-SUGGESTIONS.md](M3-R6-PAIR-SUGGESTIONS.md) for the full feature, installation and regional-review documentation.
