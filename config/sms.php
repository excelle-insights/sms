<?php

declare(strict_types=1);

return [
    'table_prefix' => $_ENV['SMS_TABLE_PREFIX'] ?? 'sms_',
    'encryption_key' => $_ENV['SMS_ENCRYPTION_KEY'] ?? '',
    'default_country' => $_ENV['SMS_DEFAULT_COUNTRY'] ?? 'KE',
    'default_provider' => $_ENV['SMS_DEFAULT_PROVIDER'] ?? null,
    'webhook_secret' => $_ENV['SMS_WEBHOOK_SECRET'] ?? null,
    'max_segments' => 6,
    'retry_attempts' => 5,
    'retry_delays' => [1, 2, 4, 8, 16],
    'idempotency_key_ttl_hours' => 24,
    'db_dsn' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_DATABASE'] ?? 'excelle',
    ),
    'db_user' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_pass' => $_ENV['DB_PASSWORD'] ?? '',
    'worker_lock_file' => __DIR__ . '/../storage/sms-worker.lock',
    'worker_run_seconds' => 55,
    'worker_batch_size' => 100,
    'worker_sleep_seconds' => 5,
];
