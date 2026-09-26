<?php

return [
    'enabled' => env('FEATURES_ENABLED', true),
    'coingecko' => [
        'enabled' => env('COINGECKO_ENABLED', false),
        'api_key' => env('COINGECKO_API_KEY'),
        'pro' => env('COINGECKO_PRO', false),
        'max_age_seconds' => 7200,
    ],
];
