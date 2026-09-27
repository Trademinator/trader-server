# M3 R6 — Help me choose pairs

This full source release includes M3 and all earlier R1–R5 repairs. On `/markets`, choose **Help me choose pairs**. The optional questionnaire builds a short, explained list of spot markets to follow. It does not predict the most profitable pair, place orders, reserve funds, or change billing.

## Install over an existing installation

Keep your database backup, `.env`, existing `APP_KEY`, storage, credentials and local configuration. Extract the archive into a temporary directory, then copy the contents of its `trader-server/` directory over the application. The archive includes compiled frontend assets and excludes databases, credentials, `vendor`, `node_modules` and runtime caches.

From the application directory, using your normal application user and PHP 8.4/8.5:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan route:clear
php artisan view:clear
php artisan config:clear
php artisan route:list --path=markets
```

The route list must include `markets.suggestions` and both `markets.preferences.*` routes. If a missing-route error remains, follow [M3-R7-ROUTE-RECOVERY.md](M3-R7-ROUTE-RECOVERY.md).

If your deployment normally uses cached configuration/routes/views, rebuild those caches using your usual procedure. If PHP OPcache retains old files, reload your PHP service through your normal hosting controls.

**Do not regenerate APP_KEY, run `migrate:fresh`, `migrate:refresh`, or `db:wipe`.** The new migration only creates `market_preference_profiles`; it does not empty or modify users, exchanges, market subscriptions or candle data. Keep APP_KEY with your backups because questionnaire answers are encrypted with it.

No new Composer/npm dependency, Artisan command, cron entry, queue job or permanent daemon is required. Existing market collection continues as documented in [crontabs.md](crontabs.md). The complete command reference remains [CLI.md](CLI.md).

## Subscriber flow

1. Open `/markets/suggestions` from the market page.
2. Select country of residence, province/state, and one exchange already used. Canadian provinces use their two-letter codes, such as ON.
3. Optionally confirm account access. This is the subscriber's declaration, not an independent legal/access verification.
4. Enter up to five existing crypto/fiat holdings, approximate value bands, a reference currency, and an allocation band. All bands are values in that reference currency; USD is not treated as USDT.
5. Choose a goal and loss comfort. Optional refinements cover essential-expense impact, when funds are needed, holding horizon, experience, monitoring, conversions and excluded assets/stablecoins.
6. Save and inspect the reasons, evidence and caveats. Review opens the ordinary market form with the exchange/pair selected. Only pressing **Subscribe** creates/reactivates a subscription.
7. Return to edit answers or delete the saved questionnaire. Deleting answers does not unsubscribe or remove the user.

Compare one exchange at a time. Changing it means reviewing the holdings for that exchange. No balances are fetched and no transfers or account opening are assumed. A learning goal and unknown answers are supported. There is no customer API-key collection in this release.

## Privacy and authorization

`market_preference_profiles` has one UUID user key and an encrypted JSON answer document in a text column. Requests always use the authenticated user's ID, never a submitted profile/user ID. The answers are hidden from model serialization, not placed in shared recommendation caches, and not included in suggestion logs. Validation uses Laravel's normal private session error/old-input mechanism. Suggestion pages send `Cache-Control: private, no-store`.

Deleting the account cascades to its questionnaire. Deleting the questionnaire removes that database row immediately; normal operator backups may retain historical copies according to the deployment's retention policy. CSRF protection, authentication and request throttles apply to the normal web routes.

## Selection and evidence rules

The catalogue checks the source-reviewed CCXT access registry. Unknown, removed or unsupported adapters are unavailable. Spot markets explicitly marked inactive are removed globally from the market catalogue; uncertain activity remains a caution. The compact catalogue now retains active state, minimum amount/cost and published taker fee without loading all CCXT market indexes. Cache keys move to v5 so older entries are refreshed.

The suggestion engine:

- Applies operator regional exclusions, explicit asset exclusions, the accumulation target, fixed tick-size availability and the user's funding/conversion boundaries.
- Allows a direct held base/quote asset, or a listed one-conversion connection within the selected exchange if requested. Conversion candidates remain exploratory; fees/minimums/amounts for that conversion are unknown. Selling uses existing spot units, never a naked short.
- Ranks preliminary matches deterministically: 40 points for direct funding, 20 for a held quote, 10 for a held base, then 30 if the goal currency is the quote or 20 if it is the base. These internal weights are preference rules, not profit probabilities. Ties use the symbol alphabetically.
- Checks at most 24 candidates in detail by default (configurable, hard cap 50), with at most 720 stored candles per candidate (configurable, hard cap 2,000). It fetches one exchange catalogue per request/cache miss, not live candle histories for every pair.
- Uses stored daily candles when present, otherwise the market's shared feed period. Calendar month/year periods are not used for these fixed-window comparisons. Incomplete/future candles are excluded.
- Requires at least 30 valid, continuous candles spanning three holding windows. The reference windows are 6 hours, 72 hours, 14 days, or 7 days for “unsure”, rounded up to whole candles. A candle period longer than the chosen window cannot qualify. The last completed candle must be no older than the greater of one hour or two periods, measured from its close.
- Calculates close-to-close drawdown and the largest absolute price move over that window. Low/medium/high historical thresholds are 10%/20%/35%; unsure and beginners use 10%. Crossing either threshold excludes that candidate. More than 10% zero-volume candles also excludes it. These are transparent screening defaults, not calibrated suitability models or guaranteed loss limits.
- Uses reciprocal prices when measuring in the base asset. Otherwise evidence is measured in the quote asset, with unmatched reference-currency returns explicitly unknown.
- Rejects minimum order values above a known allocation/funding-band upper bound only when the quote equals the reference currency. A known minimum base amount can be converted using a fresh stored closing price. It does not invent foreign-exchange rates or exact balances. Being below an upper bound does not prove affordability; the user must check exact funds and limits.
- Displays published taker fees as indicative, with actual fee tier, spread, slippage and order-book depth unknown. It does not use candle volume as an execution-liquidity guarantee.
- Presents up to five candidates, prioritizing those that passed the available screens and retaining one per base asset. This reduces repetition; cross-asset correlation is not measured.

For a trading goal, imminent money needs, impact on essential expenses, or an hours-long horizon with insufficient monitoring produce an explained empty shortlist. The learning goal can still explore markets.

## Meaning of the labels

**Explore only** applies when regional review, self-confirmed access, usable history, activity, direct funding, or material suitability answers are missing, or the goal is learning. A lack of data does not become evidence of low risk.

**Matches preference screens** means that the implemented regional, answer and historical checks passed. Exact order affordability and all execution costs are still unverified. It is not an endorsement, investment advice determination, buy/sell signal, or profitability forecast.

M3's research/backtesting infrastructure remains separate. There is no trained predictor or out-of-sample strategy-return ranking in this feature. Those require later model work.

## Regional access reviews

CCXT capability metadata does not establish availability for a subscriber's residence or individual account. `config/market_suggestions.php` deliberately ships with **no regional approvals**. The feature works immediately with clearly marked exploratory suggestions.

An operator can maintain sourced reviews in `regional_reviews`, keyed by CCXT exchange ID and `COUNTRY` or `COUNTRY-REGION`. For example, the following is a schema illustration, **not an actual exchange approval**:

```php
'example_exchange' => [
    'CA-ON' => [
        'allowed' => true,
        'reviewed_at' => 'YYYY-MM-DD',
        'source' => 'https://official-source.example/eligibility',
        'allowed_symbols' => ['BTC/CAD'], // Optional allowlist; [] excludes all.
        'excluded_symbols' => [],
    ],
],
```

Replace placeholders only after checking authoritative exchange/regulator information. Regional records override country approvals; an explicit country or regional denial always blocks. Approvals require a valid HTTPS source, a nonfuture date and a review no more than 90 days old by default. Missing, invalid and stale approvals become unknown. Denials and explicit symbol restrictions remain protective when stale. A subscriber's checkbox cannot override them. Rebuild cached Laravel configuration after modifying the file.

`trademinator:refresh-exchanges` continues refreshing technical CCXT metadata; it does **not** refresh this independent residence policy. No claim of automatic regulatory verification is made.

## Coverage and operation

Collection remains driven by explicit subscriptions and shared feeds. The questionnaire never creates a background feed or changes the automatically selected candle period. Existing stored history/backfills may provide evidence; a newly explored pair will often have insufficient history. Subscribers may explicitly follow an exploratory pair and return after collection, or operators can use existing documented historical synchronization commands. No global market watchlist is silently introduced.

The feature intentionally reports a bounded universe and missing evidence rather than manufacturing a “most profitable” ranking. Regional maintenance, execution data, portfolio correlation, exact allocation sizing, multiple-exchange comparisons and predictive returns remain outside this release.

## Verification

Automated coverage includes authenticated ownership, encrypted storage, deletion/cascade behavior, input limits, explicit subscription confirmation, inactive/excluded markets, direct/conversion funding, allocation limits, regional restrictions and stale reviews, financial/monitoring conflicts, valid/stale/gapped/malformed candle history, incomplete-candle exclusion and inverse pricing.

The standalone 128 MiB catalogue regression also renders the questionnaire results after loading 5,000 simulated Binance pairs with all 110 exchanges configured. Tests use guarded SQLite `:memory:` exclusively; no deployment database is used.
