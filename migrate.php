#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone migration runner for the Excelle Insights SMS package.
 *
 * Usage:
 *   php migrate.php [path/to/config.php]
 *
 * Configuration is loaded from a `.env` file in the current working directory
 * or from an optional PHP config file passed as the first argument.
 *
 * Required environment / config values:
 *   - DB_DSN (or DB_HOST + DB_NAME)
 *   - DB_USER
 *   - DB_PASSWORD
 *   - SMS_TABLE_PREFIX (optional, default "sms_")
 *
 * When installed as a Composer dependency the host application can run:
 *   php vendor/excelle-insights/sms/migrate.php
 */

use Dotenv\Dotenv;

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

// Load .env from the current working directory if it exists.
$envPath = getcwd();
if ($envPath !== false && is_file($envPath . '/.env')) {
    Dotenv::createImmutable($envPath)->safeLoad();
}

// Allow an optional PHP config file to override or supplement .env.
$config = [];
if (isset($argv[1])) {
    if (!file_exists($argv[1])) {
        fwrite(STDERR, "Config file not found: {$argv[1]}\n");
        exit(1);
    }

    $loaded = require $argv[1];
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

$dsn = $config['db_dsn'] ?? $_ENV['DB_DSN'] ?? '';
$user = $config['db_user'] ?? $_ENV['DB_USER'] ?? '';
$pass = $config['db_pass'] ?? $_ENV['DB_PASSWORD'] ?? '';
$tablePrefix = $config['table_prefix'] ?? $_ENV['SMS_TABLE_PREFIX'] ?? 'sms_';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "DB_DSN and DB_USER must be provided via .env or config file.\n");
    exit(1);
}

$parsed = parseDsn($dsn);
if ($parsed === null) {
    fwrite(STDERR, "Unable to parse DB_DSN: {$dsn}\n");
    exit(1);
}

$_ENV['DB_HOST'] = $parsed['host'];
$_ENV['DB_PORT'] = $parsed['port'];
$_ENV['DB_NAME'] = $parsed['name'];
$_ENV['DB_USER'] = $user;
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

    return $parts['name'] !== '' ? $parts : null;
}
