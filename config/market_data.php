<?php

return [
    'history_cache' => [
        'enabled' => (bool) env('TICKER_HISTORY_CACHE_ENABLED', true),
        'store' => env('TICKER_HISTORY_CACHE_STORE', env('CACHE_STORE', 'redis')),
        'ttl_seconds' => max(1, (int) env('TICKER_HISTORY_CACHE_TTL_SECONDS', 30)),
        'max_rows' => max(1, (int) env('TICKER_HISTORY_CACHE_MAX_ROWS', 2000)),
    ],
];
