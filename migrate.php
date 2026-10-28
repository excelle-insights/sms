#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone migration runner for the Excelle Insights SMS package.
 *
 * Usage:
 *   php migrate.php [path/to/config.php]
 *
 * The config file should return an array with:
 *   - db_dsn   (e.g. "mysql:host=127.0.0.1;port=3306;dbname=excelle;charset=utf8mb4")
 *   - db_user
 *   - db_pass
 *   - table_prefix (optional, default "sms_")
 *
 * When installed as a Composer dependency the host application can run:
 *   php vendor/excelle-insights/sms/migrate.php [config/sms.php]
 */

$autoloadFiles = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
];

$autoloader = null;
foreach ($autoloadFiles as $file) {
    if (file_exists($file)) {
        $autoloader = $file;
        break;
    }
}

if ($autoloader === null) {
    fwrite(STDERR, "Composer autoloader not found. Run `composer install`.\n");
    exit(1);
}

require $autoloader;

$configFile = $argv[1] ?? getcwd() . '/config/sms.php';
if (!file_exists($configFile)) {
    $fallback = __DIR__ . '/config/sms.php';
    if (file_exists($fallback)) {
        $configFile = $fallback;
    } else {
        fwrite(STDERR, "Config file not found: {$configFile}\n");
        exit(1);
    }
}

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "Config file must return an array.\n");
    exit(1);
}

$dsn = $config['db_dsn'] ?? '';
$user = $config['db_user'] ?? '';
$pass = $config['db_pass'] ?? '';
$tablePrefix = $config['table_prefix'] ?? 'sms_';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "Config must provide `db_dsn` and `db_user`.\n");
    exit(1);
}

$parsed = parseDsn($dsn);
if ($parsed === null) {
    fwrite(STDERR, "Unable to parse `db_dsn`: {$dsn}\n");
    exit(1);
}

$_ENV['DB_HOST'] = $parsed['host'];
$_ENV['DB_PORT'] = $parsed['port'];
$_ENV['DB_DATABASE'] = $parsed['name'];
$_ENV['DB_USERNAME'] = $user;
$_ENV['DB_PASSWORD'] = $pass;
$_ENV['SMS_TABLE_PREFIX'] = $tablePrefix;

$phinxConfig = __DIR__ . '/phinx.php';
if (!file_exists($phinxConfig)) {
    fwrite(STDERR, "Phinx configuration not found: {$phinxConfig}\n");
    exit(1);
}

$app = new \Phinx\Console\PhinxApplication();
$app->setAutoExit(false);

$input = new \Symfony\Component\Console\Input\StringInput("migrate -c {$phinxConfig}");
$status = $app->run($input);
exit($status);

/**
 * Parse a PDO MySQL DSN into Phinx-compatible connection parts.
 *
 * @return array<string, string>|null
 */
function parseDsn(string $dsn): ?array
{
    $parts = ['host' => '127.0.0.1', 'port' => '3306', 'name' => ''];

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
            case 'port':
                $parts['port'] = $value;
                break;
            case 'dbname':
            case 'database':
            case 'name':
                $parts['name'] = $value;
                break;
        }
    }

    if ($parts['name'] === '') {
        return null;
    }

    return $parts;
}
