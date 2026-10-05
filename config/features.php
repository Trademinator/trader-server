<?php

return [
    'enabled' => env('FEATURES_ENABLED', true),
    'queue' => env('FEATURES_QUEUE', 'features'),
    'queue_chunk_candles' => (int) env('FEATURES_QUEUE_CHUNK_CANDLES', 1000),
    'coingecko' => [
        'enabled' => env('COINGECKO_ENABLED', false),
        'api_key' => env('COINGECKO_API_KEY'),
        'pro' => env('COINGECKO_PRO', false),
        'max_age_seconds' => (int) env('COINGECKO_MAX_AGE_SECONDS', 7200),
    ],
];
