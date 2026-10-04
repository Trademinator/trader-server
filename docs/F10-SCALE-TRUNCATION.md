# F10 — calculation scale and premature truncation

## Scope

This change fixes the scale-8 truncation in true range, typical price and SMA.
Their dependent ATR/ATRP and CCI calculations retain the extra precision rather
than immediately losing it again. Raw OHLCV strings are not overwritten.

`EXCHANGE_ROUND_DECIMALS` remains defined and supported. This is not the
framework-agnostic Composer-package extraction, a precision-context redesign,
or a conversion of every legacy indicator to a new precision policy.
`stats_standard_deviation()`, the global `bcmax()`/`bcmin()` policy, and unrelated
indicators are not refactored here.

## Precision policy

`Technical::technical_scale(...$values)` uses:

```text
min(32, max(EXCHANGE_ROUND_DECIMALS * 2, meaningful_decimal_places + 4))
```

With the existing constant of 8, that means a minimum of 16 calculation decimal
places, four input guard digits, and a maximum of 32 places. A custom constant
still contributes to the floor, subject to that cap. This is calculation
precision, not exchange-order or display rounding.

`Bc::bcdec()` keeps its variadic calling convention and historical minimum of
two decimal places. It now accepts a named option:

```php
$this->bcdec('1.230000');                          // 6
$this->bcdec('1.230000', trimTrailingZeros: true); // 2
$this->bcdec('0.000001230000', trimTrailingZeros: true); // 8
$this->bcdec('1.23', '0.0000456000', trimTrailingZeros: true); // 7
```

Only fractional trailing zeroes are discarded for counting; leading fractional
zeroes and integer digits are not removed. Already-decimal strings are counted
directly. Scientific notation and already-float inputs are normalized through
the existing `bcconv()` implementation. Decimal strings are never converted to
floats or passed through `log10()`.

The SMA running sum retains its existing exact input precision. The current
window's sum determines the published calculation scale. A high-precision value
that has left the window cannot change the scale of later windows. EMA and SMMA
retain their prior state's precision without adding four more guard digits at
every recursive step; EMA smoothing coefficients are cached by working scale
within the call. There is no precision-context object or process-global scale
change.

This remains finite-precision, truncating arithmetic. Repeating divisions are
not exact, and the 32-place cap cannot preserve arbitrary sub-32-place changes
or guarantee a fixed relative error for every extremely small denominator.
The CCI regression allows the finite mean-deviation truncation to propagate
through the ratio instead of asserting unrealistic exact equality.

## Example

```text
high = 0.0000000123
low  = 0.0000000100

Old true range, scale 8:  0.00000000
New true range, scale 16: 0.0000000023000000
```

The true-range candidate comparison uses the same adaptive scale as the
subtractions, avoiding the separate legacy fixed-scale maximum helper.

## Version and rollout

`FeatureEngine::VERSION` changes from `m2-v3` to `m2-v4`. Existing feature rows,
frozen datasets and models are not rewritten merely by applying the patch.
Rebuild features from raw stored candles and build new datasets/models as
needed for the new feature contract. Do not inject old manually retained trait
results as new-version recursive seeds.

No database migration, Composer dependency, environment setting, new scheduler
entry, or service provider is added. Normal queue-worker restart procedures
still apply when deploying changed PHP code.

## Regression tests

```bash
vendor/bin/pest tests/Unit/TechnicalPrecisionTest.php
vendor/bin/pest tests/Unit/TechnicalIndicatorsTest.php
vendor/bin/pest tests/Unit/TechnicalSliceTest.php
```

The new file contains 29 expanded test cases: optional zero trimming, decimal
and exponent strings, large integer precision, tiny ranges and gaps, typical
price, exact SMA accumulation, all three ATR averaging modes, ATRP, CCI, zero
paths, raw-value preservation, negative truncation, feature versioning, unchanged
global `bcscale()`, and exact batch/slice agreement for batch sizes 1, 7, 23 and
500. Two old SMA expectations are updated from exchange-scale output to
calculation-scale output.
