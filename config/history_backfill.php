<?php

return [
    'enabled' => env('HISTORY_BACKFILL_ENABLED', true),
    'queue' => env('HISTORY_BACKFILL_QUEUE', 'history'),
    'page_size' => 90,
    'requests_per_job' => 5,
    'max_seconds' => 45,
    'empty_windows_before_pause' => 3,
    'errors_before_pause' => 5,
];
