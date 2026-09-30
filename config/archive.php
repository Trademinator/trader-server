<?php

return [
    'enabled' => (bool) env('ARCHIVE_ENABLED', true),
    'root' => env('ARCHIVE_PATH', storage_path('app/private/trademinator-archive')),
    'format_version' => 1,
    'ticker_schema_version' => 1,
    'feature_checkpoint_version' => 1,
    'gzip_level' => min(9, max(1, (int) env('ARCHIVE_GZIP_LEVEL', 6))),
    'archive_after_days' => max(32, (int) env('ARCHIVE_AFTER_DAYS', 90)),
    // M4.3 deliberately starts export/verify only. This switch is intentionally
    // false and is not consumed by an automatic pruning scheduler.
    'pruning_enabled' => false,
];
