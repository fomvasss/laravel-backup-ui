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


    // Timeout for sync backup operations (in seconds)
    'timeout' => 300,

    // Show detailed error output for debugging
    'show_detailed_errors' => true,

    // Queue configuration for async backup creation
    'queue' => [
        // Enable queue processing (set to false for synchronous backups)
        'enabled' => false,

        // Queue name (leave null to use default queue)
        // Example: 'backups', 'default', etc.
        'name' => null,
    ],
];
