<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$envPath = is_file(getcwd() . '/.env') ? getcwd() : __DIR__;
Dotenv::createImmutable($envPath)->safeLoad();

$dsn = $_ENV['DB_DSN'] ?? '';
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$name = $_ENV['DB_NAME'] ?? 'excelle';

if ($dsn !== '') {
    $parsed = parseDsnForPhinx($dsn);
    if ($parsed !== null) {
        $host = $parsed['host'];
        $name = $parsed['name'];
    }
}

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $host,
            'name' => $name,
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
            'table_prefix' => $_ENV['SMS_TABLE_PREFIX'] ?? 'sms_',
        ],
    ],
];

/**
 * @return array<string, string>|null
 */
function parseDsnForPhinx(string $dsn): ?array
{
    $parts = ['host' => '127.0.0.1', 'name' => ''];

    if (!str_starts_with($dsn, 'mysql:')) {
        return null;
    }

    $params = substr($dsn, strlen('mysql:'));
    foreach (explode(';', $params) as $pair) {
        $pair = trim($pair);
        if ($pair === '') {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        switch ($key) {
            case 'host':
            case 'hostname':
                $parts['host'] = $value;
                break;
            case 'dbname':
            case 'database':
            case 'name':
                $parts['name'] = $value;
                break;
        }
    }

    return $parts['name'] !== '' ? $parts : null;
}
