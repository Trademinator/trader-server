<?php

return [
    // Bound both database reads and the visible shortlist; no live candle scan.
    'candidate_limit' => 24,
    'shortlist_limit' => 5,
    'candle_limit' => 720,
    'stablecoins' => ['USDT', 'USDC', 'DAI', 'USDP', 'TUSD', 'FDUSD', 'PYUSD', 'EURC'],
    // Historical screening preferences, not forecasts or loss ceilings.
    'risk_limits' => ['low' => 0.10, 'medium' => 0.20, 'high' => 0.35, 'unsure' => 0.10],
    // Operator-maintained, sourced access reviews. No jurisdiction is approved by default.
    // Keys are exchange IDs, then COUNTRY or COUNTRY-REGION (e.g. CA-ON).
    // Each review: allowed (bool), reviewed_at (YYYY-MM-DD), source (https URL),
    // optional allowed_symbols (list), excluded_symbols (list).
    // An explicit denial always wins over the subscriber's access confirmation.
    'regional_reviews' => [],
    'regional_review_max_days' => 90,
];
