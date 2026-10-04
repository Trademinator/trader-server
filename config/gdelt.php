<?php

return [
    'enabled' => env('GDELT_ENABLED', true),
    'lastupdate_url' => env('GDELT_LASTUPDATE_URL', 'https://data.gdeltproject.org/gdeltv2/lastupdate.txt'),
    'timeout_seconds' => max(5, (int) env('GDELT_HTTP_TIMEOUT', 30)),
    'download_timeout_seconds' => max(10, (int) env('GDELT_DOWNLOAD_TIMEOUT', 60)),
    'connect_timeout_seconds' => max(2, (int) env('GDELT_HTTP_CONNECT_TIMEOUT', 5)),
    'http_attempts' => min(5, max(1, (int) env('GDELT_HTTP_ATTEMPTS', 3))),
    'retry_delay_ms' => max(0, (int) env('GDELT_RETRY_DELAY_MS', 1000)),
    'max_archive_bytes' => max(1_048_576, (int) env('GDELT_MAX_ARCHIVE_BYTES', 16_777_216)),
    'max_gkg_bytes' => max(4_194_304, (int) env('GDELT_MAX_GKG_BYTES', 134_217_728)),
    'minimum_confidence' => max(0.0, min(1.0, (float) env('GDELT_MINIMUM_CONFIDENCE', 0.45))),
    'processed_cache_key' => 'trademinator:gdelt:last-processed-gkg',
];
