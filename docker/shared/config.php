<?php

// Development configuration for the docker environment ONLY.
// In production this file is created by the installer in /shared.
return [
    'debug' => true,
    'db' => [
        'host' => 'db',
        'port' => 3306,
        'name' => 'vereinskalender',
        'user' => 'vereinskalender',
        'password' => 'dev-password',
    ],
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'dev-admin',
    ],
    'cron_token' => 'dev-cron-token',
];
