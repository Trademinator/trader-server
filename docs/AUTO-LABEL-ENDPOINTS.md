# Automatic candle-label endpoints

The first and last candles in the complete chronological input are context only.
They must remain unlabelled: no BUY, SELL, or HOLD, including completely flat dojis.
Unlabelled is not a HOLD opinion. The candles remain in the input so adjacent
candles can still use their prices and anatomy as context.

The shared `CandleAutoDetection::unlabel_endpoints()` pass clears endpoint actions
and temporary HOLD reservations. Broad BUY/SELL assignment clears stale endpoint
actions before pivot selection; HOLD assignment excludes endpoints. Both Candle
Training auto-label suggestions and candle-period viability enforce the rule again
after all label-producing passes.

For Action Training, these are the endpoints of the full frozen dataset supplied
to the algorithm, not the endpoints of a chart viewport or a 50-candle response
page. Interior page-edge candles remain eligible. One- and two-candle inputs have
no eligible interior candles. Empty viability inputs still produce zero labels.
Existing stricter pattern-context requirements are unchanged.

For period viability, context-only endpoints are excluded from the finalized
BUY + SELL + HOLD denominator as well as the individual action counts.

This change affects newly generated automatic labels. It does not delete saved
human opinions or rewrite already-built models, and it does not change manual
labelling permissions. Auto-label suggestions still require review and Submit.
On a new run with extended input, a former endpoint can become an eligible
interior candle; the new endpoints remain unlabelled.
