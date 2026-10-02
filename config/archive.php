<?php

return [
    'enabled' => (bool) env('ARCHIVE_ENABLED', true),
    'root' => env('ARCHIVE_PATH', storage_path('app/private/trademinator-archive')),
    'format_version' => 1,
    'ticker_schema_version' => 1,
    'feature_checkpoint_version' => 1,
    'gzip_level' => min(9, max(1, (int) env('ARCHIVE_GZIP_LEVEL', 6))),
    'archive_after_days' => max(32, (int) env('ARCHIVE_AFTER_DAYS', 90)),
    'portable_format_version' => 2,
    'portable_root' => env('ARCHIVE_PORTABLE_PATH', storage_path('app/private/portable')),
    'portable_queue' => env('ARCHIVE_PORTABLE_QUEUE', 'archive'),
    'portable_part_max_rows' => max(1000, (int) env('ARCHIVE_PORTABLE_PART_MAX_ROWS', 10000)),
    'portable_part_max_uncompressed_bytes' => max(4, (int) env('ARCHIVE_PORTABLE_PART_MAX_UNCOMPRESSED_MB', 32)) * 1024 * 1024,
    'portable_part_max_compressed_bytes' => max(4, (int) env('ARCHIVE_PORTABLE_PART_MAX_COMPRESSED_MB', 64)) * 1024 * 1024,
    'portable_line_max_bytes' => max(64, (int) env('ARCHIVE_PORTABLE_LINE_MAX_KB', 1024)) * 1024,
    'portable_manifest_max_kb' => max(64, (int) env('ARCHIVE_PORTABLE_MANIFEST_MAX_KB', 2048)),
    'portable_max_parts' => max(1, (int) env('ARCHIVE_PORTABLE_MAX_PARTS', 10000)),
    'portable_retention_hours' => max(1, (int) env('ARCHIVE_PORTABLE_RETENTION_HOURS', 24)),
    // M4.3 deliberately starts export/verify only. This switch is intentionally
    // false and is not consumed by an automatic pruning scheduler.
    'pruning_enabled' => false,
];
