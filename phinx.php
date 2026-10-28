<?php

declare(strict_types=1);

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'smsphinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_DATABASE'] ?? 'excelle',
            'user' => $_ENV['DB_USERNAME'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
            'table_prefix' => $_ENV['SMS_TABLE_PREFIX'] ?? 'sms_',
        ],
    ],
];
