<?php

return [
    'owner_uuid' => env('OWNER_UUID'),
    'syslog_enabled' => (bool) env('ACTION_SYSLOG_ENABLED', true),
    'syslog_ident' => env('ACTION_SYSLOG_IDENT', 'trademinator'),
    'syslog_facility' => env('ACTION_SYSLOG_FACILITY', LOG_LOCAL0),
    'access_enabled' => (bool) env('ACCESS_STATISTICS_ENABLED', true),
    'retention_days' => max(1, (int) env('ACCESS_STATISTICS_RETENTION_DAYS', 90)),
    'geoip_database' => env('GEOIP_DATABASE_PATH', storage_path('app/private/GeoLite2-City.mmdb')),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
