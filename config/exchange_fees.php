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
    | Rates are decimal fractions: 0.002 = 0.20%, 0.005 = 0.50%.
    |
    */
    'taker_overrides' => [
        'ndax' => [
            'rate' => 0.002,
            'reviewed_at' => '2026-10-01',
            'source' => 'https://ndax.io/en/fees',
            'note' => 'NDAX publishes a flat 0.20% fee per buy or sell.',
        ],
        'cryptocom' => [
            'rate' => 0.005,
            'reviewed_at' => '2026-10-01',
            'source' => 'https://crypto.com/exchange/document/fees-limits',
            'note' => 'Crypto.com Exchange Level 1 base spot taker fee without CRO balance is 0.50%; account volume/CRO discounts may reduce the actual fee.',
        ],
    ],
];
