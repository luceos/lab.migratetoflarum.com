<?php

return [
    'website_scan_wait' => env('SCANNER_SCAN_WAIT', 5), // minutes
    'show_recent_count' => env('SCANNER_SHOW_RECENT', 5),
    'keep_max_response_body_size' => env('SCANNER_KEEP_RESPONSE_SIZE', 50000),
    'extension_versions_cache' => env('SCANNER_EXTENSION_VERSIONS_CACHE', 1440),

    'client' => [
        'user_agent' => env('SCANNER_USER_AGENT', 'MigrateToFlarum Lab'),
        'connect_timeout' => env('SCANNER_CONNECT_TIMEOUT', 30),
        'timeout' => env('SCANNER_TIMEOUT', 30),
    ],
];
