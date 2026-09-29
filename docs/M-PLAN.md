# Trademinator M plan

Updated 2026-09-29. This plan follows the current Server implementation and preserves the M0–M4.1 foundations. M4.2 delivers the subscribed-market dashboard; Client execution reporting and paper trading remain M5 work.

The M4.2 integration is based on GitHub `main` at `a2c7d8f2e67cf5e1bb7c68db448ced359274f781`. It preserves the owner administration, account suspension, access statistics, syslog, local GeoIP and compression changes already present there. Dashboard routes, model relationships, frontend loading and scheduled recording/discovery are integrated alongside those features.

## Product and responsibility contract

A **market subscription** means the user follows one exact exchange + spot symbol. It enables access to that market's intelligence and contributes to shared Server collection. Subscribing does **not** mean the user holds the asset, allocates funds, enables a strategy, or places an order.

The Client decides whether to trade. An active subscription to the selected exchange + symbol is a prerequisite for that decision; it is not sufficient authorization to trade. Client configuration, balances, execution conditions and risk checks also apply. Do not equate `BTC/USD` on different exchanges, or `BTC/USD` with `BTC/USDT`. Unsubscribing must prevent new subscription-dependent trading decisions; safe handling of existing positions and protective orders belongs to the Client contract in M5.

The Server collects canonical exchange candles, computes features, trains and validates models, and records its signals. It does not infer execution from a subscription or a BUY/SELL signal. The Client owns orders, fills and actual positions. Until authenticated Client reporting exists, the Server displays execution as **Unknown**, never as executed, skipped or zero trades. Billing subscriptions and market subscriptions are separate concepts.

## Milestones

| Milestone | Status in this Server release | Scope and acceptance |
| --- | --- | --- |
| M0 — Stabilization | Existing baseline | Runtime, UUID persistence, canonical candle shape, indicator corrections and isolated in-memory tests. [Details](M0-README.md). |
| M1 — Trustworthy market data | Existing baseline | Exchange OHLCV, closed-candle validation, automatic period selection, shared subscription-driven feeds, bounded collection and resumable history backfill. [Details](M1-README.md). |
| M2 — Feature engine | Existing baseline | Causal, versioned indicators and optional timestamped CoinGecko training context. Exchange candles remain authoritative. [Details](M2-README.md). |
| M3 — Research and market onboarding | Existing baseline | Immutable labelled datasets, purged walk-forward evaluation, questionnaire, explained suggestions, explicit subscription and detailed market reviews. [Details](M3-README.md). |
| M4 — Validated intelligence | Existing baseline | KNN selection and validation, patterns, model registry, abstention, readiness and scheduled shared-market training. [Details](CLI.md#m4-intelligence-workflow-and-upgrade). |
| M4.1 — Cross-exchange evidence | Existing baseline | Point-in-time lead/lag evidence, daily reevaluation and downstream model validation. [Details](CLI.md#m41-cross-exchange-leadlag-and-m4-completion). |
| **M4.2 — Dashboard and observation history** | **Implemented here** | Read-only subscription overview, closed-candle charts, persistent Server observations, readiness, changes since visit, attention states, broad market context and explained discovery. Acceptance below. |
| M5 — Decisions, paper trading and Client integration | Planned | Subscription-gated Client API, explicit Client trading selection, decision/risk checks, paper evaluation and authenticated optional execution reporting. No orders or Client execution are implemented by M4.2. |
| M6 — Commercial access | Planned | Stripe/PayPal billing, renewals, entitlements and limits through the existing market-subscription entitlement boundary. Purchasing a plan must never enable trading. |

## M4.2 acceptance and implementation

- **Overview:** paginated active subscriptions, recent exchange price sparklines, current validated-model count, collection/history attention and recorded signal changes. Counts labelled “on this page” cover that page; the subscription total and changes count cover all active subscriptions.
- **Selected market:** up to 360 valid closed candles from its selected feed period, volume, UTC timestamps, manual refresh, visible-tab minute refresh and a last-10-candle table. The Server never combines exchange/period series or invents missing candles. Invalid rows, gaps and stale history are explicit.
- **Signal journal:** new shared `market_signals` observations record the original model, period, source decision time, actual recording time, action, reason, horizon and available confidence/evidence. The application rejects updates to existing observations. Consecutive repeats of the same model/source/action/reason/regime reuse the observation; state changes and later source candles append. A recovery after an intervening state is a new observation. No retrospective predictions are presented as decisions made in the past.
- **Chart markers:** BUY, SELL, supported HOLD and waiting states represent Server observations. Markers show state/model/regime changes, up to 200 within the chart range. Each appears on the first candle opening at or after the actual recording time, once that candle closes; the journal retains exact timestamps. A signal is never drawn before it was recorded. These markers are not entry prices, exit prices or fills.
- **Readiness and explanation:** reuse M4's history, model-build and validation gates. Deliberate HOLD remains distinct from insufficient evidence. An expired directional signal is not advertised as current. History ETA is conditional; validated-model ETA is unknown. Confidence is not a probability of profit.
- **Changes since visit:** remember the user's previous dashboard visit, holding the boundary stable during a 30-minute browsing session. First visit uses the last 24 hours. Show up to 20 changed observations across that user's active subscriptions, with an overall count.
- **Subscription coverage:** flag repeated base assets among the displayed subscriptions. This is coverage of followed markets, not positions, allocations or a measured portfolio correlation.
- **Discovery:** use the current user's encrypted questionnaire and saved exchange. Apply existing availability, regional, funding, goal, exclusions, affordability and historical-risk screens. CoinGecko's coin-wide 24-hour volume / market cap breaks ties between otherwise comparable preference matches. It does not establish exchange liquidity or a buy recommendation. Missing history remains exploratory; activity cannot override exclusion or risk gates.
- **Private suggestions:** exclude already followed markets and that user's 30-day dismissals before the bounded shortlist. Review links recheck the dashboard shortlist and stored evidence. Review and an explicit subscription action are required before following a suggested market. No discovery read creates markets, subscriptions, feeds, orders or canonical training context.
- **Market conditions:** optional fresh global market-cap change, BTC dominance, volume and up to five category movements. Exchange charts never use CoinGecko prices. Global discovery cache is isolated from M2's point-in-time training snapshots.
- **Access and failure handling:** dashboard, charts and discovery require a verified session. Chart/detail access requires that user's active subscription. Client execution is always Unknown. Empty history, no model, stale context, API errors, disabled discovery, missing preferences and chart-library failure have readable states. Background discovery does not block the dashboard request.

The subscription access requirement is enforced for the Server dashboard/chart/intelligence routes now. M4.2 cannot enforce exchange orders made independently by a Client; the Client API and its pre-trade subscription check are explicit M5 acceptance gates.

## M5 acceptance gates

1. Define and implement authenticated, versioned Client access to the exact subscribed market, with active-subscription checks on every request, revocation behavior and expiring responses. The Client must check subscription eligibility immediately before a new trade decision and fail closed when it cannot establish eligibility. Define safe position-exit/protective-order behavior separately.
2. Keep **following**, **Client trading enabled**, **Server signal**, **Client decision** and **exchange fill** as independent states. Trading selection is an explicit Client setting and defaults off; neither subscription nor billing toggles it.
3. Add risk and decision checks appropriate to the user's Client settings, funds, fees, spread, freshness, exposure and exchange constraints. Save the reason for each Client action or intentional skip.
4. Add paper trading with position state, costs, drawdown and an appropriate passive benchmark over matching periods. Keep paper, historical backtest and reported live results separate; show sample size, observation window and costs. Do not derive a portfolio return from a stream of classification labels.
5. Add optional, authenticated and idempotent Client reports linked to the immutable Server signal ID: acted, skipped with reason, rejected/failed, pending and fills (including partial fills). Scope all reports and balances to their owner. No report means Unknown. A Server HOLD or abstention is not a Client skip.
6. Extend charts with separately styled, timestamped Client decisions and actual fill prices only when supported by those reports. Show decision latency, execution differences and outcomes with their provenance. Preserve the original signal if a model later changes.
7. Add user-selected alerts and digest preferences after the event/reporting contract is established. Extend the existing restricted owner reports and syslog monitoring with actionable worker/collection/model failure alerts. Preserve their access controls and keep raw worker errors and private account data off user dashboards. See [existing owner administration and operations](CLI.md#owner-administration-syslog-and-local-geoip).

## Deployment and verification

The dashboard migration for the journal, dismissals and visit timestamp is already included in GitHub `main`. This integration adds no further migrations or Composer/npm dependencies; use the current lockfiles, including the existing GeoIP dependency. Build frontend assets and apply any pending migrations before serving the new dashboard or schedules:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan schedule:list
```

Use the existing persistent queue, shared atomic-lock-capable cache and cron-driven `intelligence` worker. Signal recording runs every minute; inference can be delayed by queue load and does not reconstruct missed historical decisions. The first journal entry is created when the recorder first runs, not on page load. Retained observations consume storage and need normal backup/capacity planning; this release does not silently prune history.

`DASHBOARD_SIGNALS_ENABLED` and `DASHBOARD_DISCOVERY_ENABLED` default to `true`. Signal recording also respects `INTELLIGENCE_ENABLED`. CoinGecko discovery respects `COINGECKO_ENABLED` and requires `COINGECKO_API_KEY`; its hourly refresh is separate from subscription-driven context collection. Discovery consumes at most 15 HTTP requests per default refresh (global, up to 100 coin summaries, at most 12 symbol checks and categories), shared across all users; account plan limits still apply. It uses a shared lock and the scheduler runs it in the background. Cached source data expires within two hours, including category timestamps. If a refresh fails, still-fresh cache remains usable; expired context cannot influence suggestions.

See [CLI.md](CLI.md#trademinatordispatch-market-signals) for immediate dispatch and [CRONTABS.md](CRONTABS.md#m42-dashboard-recording-and-discovery) for deployment requirements. Existing M0–M4.1 data and model artifacts are preserved. A new model build is optional to populate `horizon_candles` when using an older artifact without its label definition; unknown horizon is displayed honestly.

Integration verification includes the owner/access/syslog features together with the dashboard. The full PHP suite uses SQLite `:memory:` only:

```bash
php artisan test --compact
node --test tests/Frontend/*.test.mjs
npm run build
```

The standard PHP suite uses SQLite `:memory:` only. Follow [the test safety rules](M3-R1-RECOVERY.md); never aim tests at deployment databases.
