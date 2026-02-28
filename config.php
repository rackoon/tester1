<?php
return [
    'db_path' => __DIR__ . '/data/planner.sqlite',
    'shelly' => [
        'base_url' => getenv('SHELLY_BASE_URL') ?: 'http://127.0.0.1',
        'username' => getenv('SHELLY_USER') ?: null,
        'password' => getenv('SHELLY_PASS') ?: null,
    ],
    'ampron' => [
        'base_url' => getenv('AMPRON_BASE_URL') ?: '',
        'display_id' => getenv('AMPRON_DISPLAY_ID') ?: 'SERVICE_LOBBY',
        'username' => getenv('AMPRON_USER') ?: '',
        'password' => getenv('AMPRON_PASS') ?: '',
    ],
    'partner_api' => getenv('PARTNER_API_URL') ?: 'http://127.0.0.1:9000/permissions',
    'app_secret' => getenv('PLANNER_APP_SECRET') ?: 'change-me',
];
