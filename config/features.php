<?php

return [
    'enabled' => env('FEATURES_ENABLED', true),
    'coingecko' => [
        'enabled' => env('COINGECKO_ENABLED', false),
        'api_key' => env('COINGECKO_API_KEY'),
        'pro' => env('COINGECKO_PRO', false),
        'max_age_seconds' => 7200,
        // Explicit exchange:symbol mappings prevent ambiguous ticker matches.
        // vs_currency MUST represent the actual quote asset (USD != USDT).
        // 'kraken:BTC/USD' => ['id' => 'bitcoin', 'vs_currency' => 'usd', 'category' => 'layer-1'],
        'markets' => [],
    ],
];
