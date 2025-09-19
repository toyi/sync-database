<?php return [
    'auto' => [
        'enabled' => env('SYNC_AUTO', false),
        'at' => env('SYNC_AUTO_AT', '06:10')
    ],

    'bin' => [
        'mysql' => env('SYNC_BIN_MYSQL'),
        'mysqldump' => env('SYNC_BIN_MYSQLDUMP'),
        'pg_dump' => env('SYNC_BIN_PG_DUMP'),
        'pg_restore' => env('SYNC_BIN_PG_RESTORE'),
    ],

    'post_dump_scripts' => [
        'enabled' => env('SYNC_POST_DUMP_SCRIPTS_ENABLED', false),
        'scripts' => []
    ],

    'post_scripts' => [
        'enabled' => env('SYNC_POST_SCRIPTS_ENABLED', false),
        'scripts' => []
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
    ],

    'dump_options' => [
        'mariadb' => [
            'remove_database_qualifier' => env('SYNC_REMOVE_DATABASE_QUALIFIER', null),
        ],
        'mysql' => [
            'max_allowed_packet' => env('SYNC_DUMP_OPTIONS_MYSQL_MAX_ALLOWED_PACKET', '64M'),
            'remove_database_qualifier' => env('SYNC_REMOVE_DATABASE_QUALIFIER', null)
        ],
        'pgsql' => []
    ]
];
