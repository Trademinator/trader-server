<?php

return [
    'signals_enabled' => env('DASHBOARD_SIGNALS_ENABLED', true),
    'discovery_enabled' => env('DASHBOARD_DISCOVERY_ENABLED', true),
    'discovery_limit' => 12,
    'discovery_max_age_seconds' => 7200,
    'page_size' => 12,
];
