<?php
return [
    'db_path' => __DIR__ . '/data/planner.sqlite',
    'shelly' => [
        'base_url' => getenv('SHELLY_BASE_URL') ?: 'http://127.0.0.1/relay/0',
        'username' => getenv('SHELLY_USER') ?: null,
        'password' => getenv('SHELLY_PASS') ?: null,
    ],
    'partner_api' => getenv('PARTNER_API_URL') ?: 'http://127.0.0.1:9000/permissions',
    'app_secret' => getenv('PLANNER_APP_SECRET') ?: 'change-me',
];
