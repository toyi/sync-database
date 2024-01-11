<?php return [
    'auto' => [
      'enabled' => env('SYNC_AUTO', false),
      'at' => env('SYNC_AUTO_AT', '06:10')
    ],

    'bin' => [
        'mysql' => env('SYNC_BIN_MYSQL'),
        'mysqldump' => env('SYNC_BIN_MYSQLDUMP'),
    ],

    'post_scripts' => [
        //
    ],

    # Remote server configuration
    'ssh' => [
        'host' => env('SYNC_SSH_HOST'),
        'port' => env('SYNC_SSH_PORT', '22'),
        'user' => env('SYNC_SSH_USER', 'root'),
        'password' => env('SYNC_SSH_PASSWORD'),
        'key' => env('SYNC_SSH_KEY'),
        'timeout' => env('SYNC_SSH_TIMEOUT', 300),
    ],

    #Remote database configuration
    'database' => [
        'host' => env('SYNC_DATABASE_HOST', '127.0.0.1'),
        'port' => env('SYNC_DATABASE_PORT', '3306'),
        'name' => env('SYNC_DATABASE_NAME'),
        'user' => env('SYNC_DATABASE_USER'),
        'password' => env('SYNC_DATABASE_PASSWORD'),
        'max_allowed_packet' => env('SYNC_DATABASE_MAX_ALLOWED_PACKET', '64M'),
        'tables_no_data' => env('SYNC_TABLES_NO_DATA', ''),
    ]
];
