<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted exchange taker-fee fallbacks
    |--------------------------------------------------------------------------
    |
    | Market-specific CCXT fees always take precedence. These entries are used
    | only when CCXT omits the fee for a specific market. Keep every override
    | sourced and review it when an exchange changes its published fee schedule.
    |
    */
    'taker_overrides' => [
        'ndax' => [
            'rate' => 0.002,
            'reviewed_at' => '2026-10-01',
            'source' => 'https://ndax.io/en/fees',
            'note' => 'NDAX publishes a flat 0.20% fee per buy or sell.',
        ],
    ],
];
