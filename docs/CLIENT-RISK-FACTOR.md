# Client API: questionnaire risk factor

## Request

```http
GET /api/v1/client/risk-factor
Authorization: Bearer tmk_...
Accept: application/json
```

Use an existing Client API key from **Settings → Client API keys**. The API must
be enabled (`CLIENT_API_ENABLED=true`). The endpoint uses the same verified,
active-account checks, revoked/expired-key rejection, failed-authentication
limits, and authenticated request limit (`120/minute`) as the other Client API
routes. It requires no market subscription and no request body.

The authenticated key identifies the user. No `user_id`, market, exchange,
questionnaire answers or R override is accepted as an input to the calculation.
Query parameters cannot change whose profile is read or override its answers.

```bash
export TRADEMINATOR_URL='https://your-trademinator-server.example'
# Supply your existing Client API key securely in TRADEMINATOR_API_KEY.
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/risk-factor" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

## Contract

Successful responses use HTTP `200`, `Cache-Control: private, no-store`, and
`X-Trademinator-API-Version: 1`. R is computed from the currently stored encrypted
questionnaire on each request. The endpoint neither caches a previous profile
nor creates a profile when none exists. It does not change trading settings,
subscriptions, orders, or paper accounts. Existing authentication bookkeeping
(such as the key's last-used timestamp) is unchanged.

All fractional calculation values are **decimal strings**, not JSON floating
point numbers. R and scores have 16 decimal places; policy weights retain their
explicit two-decimal representation.

| Field | Meaning |
| --- | --- |
| `api_version` | Integer `1`, the existing Client API version. |
| `risk_factor` | Decimal R in `[0.01, 1]`. |
| `algorithm_version` | `questionnaire-risk-v1`; identifies the fixed weights, mappings and baseline. |
| `source` | `default` only when no questionnaire row exists; otherwise `questionnaire`. |
| `restraint_score` | Weighted restraint S; `null` when no questionnaire exists. |
| `components` | The four risk-relevant components, or `[]` when no questionnaire exists. |
| `unknown_fields` | Fields using baseline substitution; `[]` for no saved questionnaire. |
| `questionnaire_updated_at` | Saved profile update time in UTC, or `null`. |
| `calculated_at` | Response calculation time in UTC. |

Each component includes its recognized `answer` (or `null`), `status`, `weight`,
`score`, and `weighted_score`. Status is `answered`, `unsure`, `missing`, or
`unrecognized`. Only recognized answer enums and the literal `unsure` are echoed;
arbitrary malformed values and unrelated questionnaire data are never returned.

### No saved questionnaire

R is exactly `0.25`; trailing zeroes only represent the wire format. This also
applies after the user deliberately deletes their saved questionnaire.

```json
{
  "api_version": 1,
  "risk_factor": "0.2500000000000000",
  "algorithm_version": "questionnaire-risk-v1",
  "source": "default",
  "restraint_score": null,
  "components": [],
  "unknown_fields": [],
  "questionnaire_updated_at": null,
  "calculated_at": "2026-10-04T19:00:00.000000Z"
}
```

### Example saved questionnaire

These illustrative timestamps are not an expiry contract. Here the user reports
no essential-expense impact, medium loss tolerance, needing money within a year,
and some experience. S is `0.30` and R is approximately `0.2511886431509580`.

```json
{
  "api_version": 1,
  "risk_factor": "0.2511886431509580",
  "algorithm_version": "questionnaire-risk-v1",
  "source": "questionnaire",
  "restraint_score": "0.3000000000000000",
  "components": {
    "loss_impact": {
      "answer": "no",
      "status": "answered",
      "weight": "0.40",
      "score": "0.0000000000000000",
      "weighted_score": "0.0000000000000000"
    },
    "risk": {
      "answer": "medium",
      "status": "answered",
      "weight": "0.30",
      "score": "0.5000000000000000",
      "weighted_score": "0.1500000000000000"
    },
    "money_needed": {
      "answer": "months",
      "status": "answered",
      "weight": "0.20",
      "score": "0.5000000000000000",
      "weighted_score": "0.1000000000000000"
    },
    "experience": {
      "answer": "some",
      "status": "answered",
      "weight": "0.10",
      "score": "0.5000000000000000",
      "weighted_score": "0.0500000000000000"
    }
  },
  "unknown_fields": [],
  "questionnaire_updated_at": "2026-10-04T18:55:00.000000Z",
  "calculated_at": "2026-10-04T19:00:00.000000Z"
}
```

## Versioned calculation

```text
S = 0.40 × x_loss_impact
  + 0.30 × x_risk
  + 0.20 × x_money_needed
  + 0.10 × x_experience

R = 100^(-S)
  = product(100^(-weight_i × x_i))
```

| Questionnaire field | Weight | Known answer → restraint score |
| --- | --- | --- |
| `loss_impact` | `0.40` | `no` → `0`; `yes` → `1` |
| `risk` | `0.30` | `high` → `0`; `medium` → `0.5`; `low` → `1` |
| `money_needed` | `0.20` | `later` → `0`; `months` → `0.5`; `soon` → `1` |
| `experience` | `0.10` | `experienced` → `0`; `some` → `0.5`; `new` → `1` |

A missing, null, `unsure`, or unrecognized individual answer uses:

```text
x_unknown = ln(4) / ln(100) = log10(2)
          ≈ 0.3010299956639811952137388947244930267682
```

Weights are **not** redistributed when an answer is unknown. Known restrictions
remain in the calculation. An empty saved answer map therefore produces R `0.25`
with `source=questionnaire`, rather than pretending no profile exists. Missing
fields are not filled from `Questionnaire::defaults()`: for example, a missing
experience answer must not be silently treated as `new`.

The existing form explicitly stores `experience=new` by default. When that value
is present in a saved profile it is a known answer with score `1`, not an unknown.
Thus a submitted form with the three `unsure` answers and `experience=new` does
not produce the same result as a completely absent questionnaire.

Each restraint is in `[0, 1]` and the weights total `1`, so the model itself gives
`0 <= S <= 1` and `0.01 <= R <= 1`. There is no `min`, `max`, clipping, market
volatility adjustment, model-confidence adjustment, or amount-allocation cap in
the calculation. The numeric constants and weights are immutable within this
algorithm version; changing the policy requires a new version, not an unnoticed
`.env` adjustment. These policy weights are not statistically fitted estimates.

### Decimal implementation

The calculator uses native BCMath operations with an explicit 32-place working
scale and PHP 8.4 `bcround(..., 16, RoundingMode::HalfEven)` for output. It does not
change global `bcscale()`, convert inputs to floats, or use
`EXCHANGE_ROUND_DECIMALS`. PHP 8.4 and `ext-bcmath` are already required by the
project; no new Composer dependency is introduced.

Native `bcpow` cannot take a fractional exponent, so the bounded model evaluates
`1 / exp(S × ln(100))` through a positive Taylor series after division by 16, then
four squarings. This internal calculation is restricted to the model's `[0, 1]`
score domain; it is not a new general-purpose BCMath API. A convergence or domain
failure raises an error rather than clipping R or issuing a default. The
16-place result is rounded only after the full calculation. Displayed component
scores are also rounded, so their displayed sum can differ from displayed S in
the final decimal place when unknown values are present.

The fixture `tests/Fixtures/client-risk-factor-v1.json` contains all 192
combinations of known/unknown inputs, generated independently using Python
`Decimal` at 80-digit precision with `100 ** (-S)` and half-even output rounding.
The unit tests compare the production calculation against each reference.

## Errors and Client handling

The inherited API returns `401` for missing/invalid/revoked/expired credentials,
`403` for an unverified or unavailable account, `429` for rate limiting, and `503`
when the Client API is disabled.

An unreadable encrypted profile or malformed whole-profile payload returns HTTP
`503` with no `risk_factor`:

```json
{
  "api_version": 1,
  "error": {
    "code": "risk_profile_unavailable",
    "message": "The saved risk questionnaire could not be read. No risk factor was issued."
  }
}
```

Database outages and unexpected calculation failures also remain errors; they
are not converted into a successful default response. Unknown individual
answers within a readable map are different from an unreadable whole profile.

The Client must not interpret an HTTP/network/parse error as permission to use
`0.25`, and must not accept a factor belonging to another account. Request a
fresh value for the active user's sizing context and record the factor and
algorithm version used for the decision. This endpoint adds no push mechanism,
background task, or promise that a fetched factor cannot change later. An edit
or deletion is reflected by the next successful request.

The Client's agreed sizing relationship remains:

```text
Qrisk = Qdistributed_capital × R
Qbuy  = min(Q3bar, Qrisk)
```

The separate order-sizing comparison above is not part of deriving R. The Client
continues to account for existing exposure and pending orders, actual balances,
fees, lot steps and exchange minimums. A positive R does not require a purchase
and is not an upper bound on losses. A below-minimum order should not be rounded
up beyond the available risk-adjusted budget simply to make it tradable.

This change exposes R only. It does not modify the existing Server decision or
paper-trading engines or reapply R to an amount that has already been adjusted
by the Client. It does not replace live-trading permission or other safety gates.

## Apply and verify

No migration, additional `.env` variable, queue job, cron entry, or Composer
update is required for this endpoint. Clear the route cache after applying it.

```bash
php artisan optimize:clear
php artisan route:list --path=api/v1/client/risk-factor
php artisan test --compact tests/Unit/RiskFactorCalculatorTest.php tests/Feature/ClientRiskFactorApiTest.php
```

The feature tests cover authentication, revoked/expired keys, account checks,
defaults, profile isolation, fresh reads, encrypted payload failures, response
privacy, HTTP method and inherited middleware. The unit tests cover the exact
baseline, endpoints, approved examples, every discrete answer combination,
monotonicity, malformed/unknown individual answers and global-scale independence.
