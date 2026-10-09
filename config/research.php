<?php

return [
    // Private, local/shared filesystem; never expose this directory under public/.
    'path' => storage_path('app/private/research'),
    // Backtests load the selected vectors into memory. Fail, never silently truncate.
    'max_rows' => 50000,
    // Maximum dataset build duration in seconds.
    'dataset_max_seconds' => max(60, (int) env('RESEARCH_DATASET_MAX_SECONDS', 540)),
];
