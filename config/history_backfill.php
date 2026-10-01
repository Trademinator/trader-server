<?php

return [
    'enabled' => env('HISTORY_BACKFILL_ENABLED', true),
    'queue' => env('HISTORY_BACKFILL_QUEUE', 'history'),
    'page_size' => 90,
    'requests_per_job' => 5,
    'max_seconds' => 45,
    'empty_windows_before_pause' => 3,
    'errors_before_pause' => 5,
    // Scan selected-period history lazily and persist closed-candle holes. The
    // scheduled backfill command runs every minute, but each market is rescanned
    // only at this interval unless the command is explicitly filtered.
    'gap_scan_interval_minutes' => env('HISTORY_GAP_SCAN_INTERVAL_MINUTES', 360),
    'gap_scan_markets_per_run' => env('HISTORY_GAP_SCAN_MARKETS_PER_RUN', 10),
    'gap_repair_dispatch_limit' => env('HISTORY_GAP_REPAIR_DISPATCH_LIMIT', 20),
    'gap_empty_attempts_before_unavailable' => env('HISTORY_GAP_EMPTY_ATTEMPTS_BEFORE_UNAVAILABLE', 5),
    // Period selection must reach far enough back for the normal model train/test window
    // and at least this many calendar days. The probe is read-only and never fills gaps.
    'minimum_days' => env('HISTORY_BACKFILL_MINIMUM_DAYS', 7),
    'depth_probe_candles' => env('HISTORY_BACKFILL_DEPTH_PROBE_CANDLES', 12),
    'reselect_shallow_periods' => env('HISTORY_BACKFILL_RESELECT_SHALLOW_PERIODS', true),
];
