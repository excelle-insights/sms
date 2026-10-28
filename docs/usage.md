# Usage

This guide covers the most common operations with the `excelle-insights/sms` package.

## Installation

```bash
composer require excelle-insights/sms
```

## Configuration

Create a `.env` file in your project root (see `.env.example` in this package):

```dotenv
DB_HOST=127.0.0.1
DB_NAME=kenya_homes
DB_USER=root
DB_PASSWORD=
DB_DSN=mysql:host=127.0.0.1;dbname=kenya_homes

SMS_TABLE_PREFIX=sms_
SMS_ENCRYPTION_KEY=
SMS_DEFAULT_COUNTRY=KE
SMS_DEFAULT_PROVIDER=africastalking
```

You can also create a bootstrap config file if you prefer:

```php
<?php

return [
    'table_prefix' => $_ENV['SMS_TABLE_PREFIX'] ?? 'sms_',
    'encryption_key' => $_ENV['SMS_ENCRYPTION_KEY'],
    'default_country' => $_ENV['SMS_DEFAULT_COUNTRY'] ?? 'KE',
    'default_provider' => $_ENV['SMS_DEFAULT_PROVIDER'] ?? null,
    'webhook_secret' => $_ENV['SMS_WEBHOOK_SECRET'] ?? null,
    'max_segments' => 6,
    'retry_attempts' => 5,
    'retry_delays' => [1, 2, 4, 8, 16],
    'idempotency_key_ttl_hours' => 24,
    'db_dsn' => $_ENV['DB_DSN'] ?? sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'excelle',
    ),
    'db_user' => $_ENV['DB_USER'] ?? 'root',
    'db_pass' => $_ENV['DB_PASSWORD'] ?? '',
];
```

Run the migrations:

```bash
php vendor/excelle-insights/sms/migrate.php
```

The runner loads `.env` from the current working directory and uses `DB_DSN`, `DB_USER` and `DB_PASSWORD`. You can optionally pass a PHP config file to override values:

```bash
php vendor/excelle-insights/sms/migrate.php /path/to/config/sms.php
```

## Initialise the manager

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
```

## Provider configuration

Store credentials per tenant or globally:

```php
// Global Africa's Talking credentials
$sms->setProviderCredentials('africastalking', null, [
    'username' => 'my_username',
    'api_key' => 'my_api_key',
], 'MySenderID');

// Tenant-specific Celcom credentials
$sms->setProviderCredentials('celcom', 7, [
    'partner_id' => 'P123',
    'api_key' => 'my_api_key',
], 'MySenderID');
```

## Default provider

```php
// Global default
$sms->setDefaultProvider('africastalking');

// Per-tenant default
$sms->setDefaultProvider('celcom', 7);
```

Resolution order for every send:

1. Provider explicitly passed in the call
2. Tenant default
3. Global default
4. `SMS_DEFAULT_PROVIDER` from `.env`
5. Throw `NoDefaultProviderException`

## Send a single SMS

```php
$result = $sms->send('0712345678', 'Your OTP is 4821', [
    'tenant_id' => 7,
    'sender_id' => 'MySenderID',
]);

$result->status;        // MessageStatus::QUEUED
$result->localId;       // local message id
```

## Send from a template

```php
$sms->templates()->create([
    'tenant_id' => 7,
    'key' => 'invoice_due',
    'name' => 'Invoice due reminder',
    'body' => 'Hi {name}, invoice {ref} of {amount} is due on {due_date}.',
    'category' => 'transactional',
]);

$result = $sms->send('0712345678', '', [
    'tenant_id' => 7,
    'template' => 'invoice_due',
    'template_vars' => [
        'name' => 'Wanjiru',
        'ref' => 'INV-001',
        'amount' => 'KES 5,000',
        'due_date' => '5 Nov',
    ],
]);
```

## Bulk send

```php
$batch = $sms->sendBulk([
    ['to' => '0712345678', 'vars' => ['name' => 'Wanjiru']],
    ['to' => '0723456789', 'vars' => ['name' => 'Otieno']],
], 'Hi {name}, your statement is ready.', [
    'tenant_id' => 7,
    'template' => 'statement_ready',
]);

$batch->batchId;
$batch->total;
$batch->queued;
$batch->failed;
```

## Scheduling

```php
$sms->send('0712345678', 'Reminder', [
    'tenant_id' => 7,
    'scheduled_at' => '2026-11-05 09:00:00',
    'timezone' => 'Africa/Nairobi',
]);
```

The worker releases due scheduled messages and dispatches them.

## Approval workflow

```php
$result = $sms->send('0712345678', 'Campaign message', [
    'tenant_id' => 7,
    'pending' => true,
]);

// Later
$sms->status()->approve($result->localId, new StatusContext(actor: $user->id));
$sms->status()->reject($result->localId, new StatusContext(actor: $user->id, reason: 'Wrong audience'));
```

## Worker

Run the worker every minute from cron:

```cron
* * * * * /usr/bin/php /var/www/app/vendor/excelle-insights/sms/examples/worker.php >> /var/log/sms-worker.log 2>&1
```

Or use Supervisor. The worker handles:

- releasing due scheduled messages
- dispatching queued messages
- retrying failed messages with exponential backoff

## Webhooks

Mount the example callback in your application:

```php
// Laravel
Route::post('/sms/webhook/{provider}', function () {
    require '/path/to/vendor/excelle-insights/sms/examples/webhook.php';
});
```

Configure verification per provider:

```php
'providers' => [
    'africastalking' => [
        'webhook_secret' => 'shared-secret',
        // or
        'webhook_allowed_ips' => ['192.168.1.1'],
    ],
],
```

The secret can be passed as a query parameter (`?secret=...`) or in the `X-Webhook-Secret` header.

## Opt-outs

Marketing sends are automatically blocked for opted-out numbers. Transactional sends can bypass with `bypass_opt_out: true`.

```php
// Programmatic opt-out
$sms->optOut('0712345678', 7, 'customer-portal');

if ($sms->isOptedOut('0712345678', 7)) {
    // skip
}
```

Inbound messages containing `STOP`, `UNSUBSCRIBE`, `OPT OUT`, `OPT-OUT` or `CANCEL` are automatically added to the opt-out list.

## Provider adapters

v1 ships with:

- `africastalking` — send, bulk, DLR webhooks, inbound SMS, balance
- `celcom` — send, bulk, balance
- `log` — local development, writes to log

Add a custom provider by implementing `SmsProviderInterface` and registering it:

```php
$sms->registerProvider(new MyProvider());
```
