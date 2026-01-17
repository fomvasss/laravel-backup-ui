<?php

return [
    'route_prefix' => 'backup',

    'middleware' => ['web', 'auth'],

    'page_title' => 'Backup Management',

    'per_page' => 15,

    'allowed_users' => [
        // 'admin@example.com'
    ],

    'auth_callback' => null, // Custom auth callback function


    // Timeout for backup operations (in seconds)
    'timeout' => 300,

    // Show detailed error output for debugging
    'show_detailed_errors' => true,

    // External disk support configuration
    'external_disks' => [
        // Timeout for remote operations (seconds)
        'timeout' => 30,

        // Retry attempts for remote operations
        'retry_attempts' => 3,

        // Supported external disk drivers
        'supported_drivers' => [
            's3', 'gcs', 'google', 'ftp', 'sftp', 'dropbox', 'rackspace'
        ],
    ],
];
