<?php

return [
    'enabled' => env('HISTORY_BACKFILL_ENABLED', true),
    'queue' => env('HISTORY_BACKFILL_QUEUE', 'history'),
    'page_size' => 90,
    'requests_per_job' => 5,
    'max_seconds' => 45,
    'empty_windows_before_pause' => 3,
    'errors_before_pause' => 5,
    // Period selection must reach far enough back for the normal model train/test window
    // and at least this many calendar days. The probe is read-only and never fills gaps.
    'minimum_days' => env('HISTORY_BACKFILL_MINIMUM_DAYS', 7),
    'depth_probe_candles' => env('HISTORY_BACKFILL_DEPTH_PROBE_CANDLES', 12),
    'reselect_shallow_periods' => env('HISTORY_BACKFILL_RESELECT_SHALLOW_PERIODS', true),
];
