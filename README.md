# Excelle Insights SMS

Multi-provider SMS package for Excelle applications.

[![CI](https://github.com/excelle-insights/sms/actions/workflows/ci.yml/badge.svg)](https://github.com/excelle-insights/sms/actions/workflows/ci.yml)

## Features

- One consistent API for sending and receiving SMS across multiple providers.
- Database-backed default provider selection, per tenant or global.
- Encrypted provider credentials at rest.
- Local-first persistence with retry queue, idempotency and status audit trail.
- Message templates with versioning.
- Opt-out enforcement.
- Webhook-driven delivery reports and inbound SMS.
- Framework-agnostic; bring your own PDO, HTTP client and logger.

## Supported providers (v1)

| Provider | Send | Bulk | DLR webhook | Inbound | Balance |
| --- | --- | --- | --- | --- | --- |
| Africa's Talking | Yes | Yes | Yes | Yes | Yes |
| Celcom Africa | Yes | Yes | No | No | Yes |

## Requirements

- PHP >= 8.1
- PDO MySQL
- Sodium extension

## Installation

```bash
composer require excelle-insights/sms
```

## Quick start

Create a `.env` file in your project root (copy from `.env.example`):

```dotenv
DB_HOST=127.0.0.1
DB_NAME=kenya_homes
DB_USER=root
DB_PASSWORD=
DB_DSN=mysql:host=127.0.0.1;dbname=kenya_homes
SMS_DEFAULT_PROVIDER=africastalking
```

Run the migrations:

```bash
php vendor/excelle-insights/sms/migrate.php
```

Use the package in your code:

```php
use ExcelleInsights\Sms\Facade\SmsManager;
use ExcelleInsights\Sms\Support\DatabaseLogger;
use ExcelleInsights\Sms\Support\DefaultHttpClient;

$config = require __DIR__ . '/config/sms.php';

$pdo = new PDO(
    $config['db_dsn'],
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$sms = new SmsManager(
    $config,
    $pdo,
    new DefaultHttpClient(),
    new DatabaseLogger($pdo, $config['table_prefix']),
);

$sms->setProviderCredentials('africastalking', null, [
    'username' => 'my_username',
    'api_key' => 'my_api_key',
], 'MySenderID');

$sms->setDefaultProvider('africastalking');

$sms->send('+254712345678', 'Hello from Excelle');
```

See `docs/usage.md` for the full guide.

## Running tests

```bash
composer install
composer test
composer analyse
composer style
```

## License

MIT
