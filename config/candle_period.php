<?php

return [
    // Bump this whenever automatic period-selection semantics change. Existing
    // shared feeds keep their current period until the new version succeeds.
    'selection_version' => env('CANDLE_PERIOD_SELECTION_VERSION', 2),
    'periods' => ['1m', '3m', '5m', '15m', '30m', '1h', '4h', '1d'],
    'quality_threshold' => env('CANDLE_PERIOD_QUALITY_THRESHOLD', 0.7),
    'minimum_coverage' => env('CANDLE_PERIOD_MINIMUM_COVERAGE', 0.8),
    'minimum_candles' => env('CANDLE_PERIOD_MINIMUM_CANDLES', 50),
    'evaluation_days' => env('CANDLE_PERIOD_EVALUATION_DAYS', 7),
    'fallback_days' => env('CANDLE_PERIOD_FALLBACK_DAYS', 7),
    'minimum_action_ratio' => env('CANDLE_PERIOD_MINIMUM_ACTION_RATIO', 0.01),
    'backfill_retry_minutes' => env('CANDLE_PERIOD_BACKFILL_RETRY_MINUTES', 15),
    'no_candidate_retry_minutes' => env('CANDLE_PERIOD_NO_CANDIDATE_RETRY_MINUTES', 360),
    'scheduled_markets_per_run' => env('CANDLE_PERIOD_SCHEDULED_MARKETS_PER_RUN', 10),
];
