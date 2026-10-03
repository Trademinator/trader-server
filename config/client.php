<?php

return [
    'enabled' => env('CLIENT_API_ENABLED', true),
    'response_ttl_seconds' => max(5, (int) env('CLIENT_API_RESPONSE_TTL_SECONDS', 30)),
    'state_max_age_seconds' => max(5, (int) env('CLIENT_API_STATE_MAX_AGE_SECONDS', 30)),
    'max_keys' => max(1, (int) env('CLIENT_API_MAX_KEYS', 10)),
    'default_paper_quote' => env('CLIENT_PAPER_INITIAL_QUOTE', '10000'),
    'default_max_signal_drift_bps' => max(0.0001, min(10000.0, (float) env('CLIENT_DEFAULT_MAX_SIGNAL_DRIFT_BPS', 100))),
];
