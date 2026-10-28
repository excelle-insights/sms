<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ExcelleInsights\Sms\Facade\SmsManager;
use ExcelleInsights\Sms\Support\DatabaseLogger;
use ExcelleInsights\Sms\Support\DefaultHttpClient;

$envPath = is_file(getcwd() . '/.env') ? getcwd() : __DIR__ . '/..';
Dotenv::createImmutable($envPath)->safeLoad();

$config = require __DIR__ . '/../config/sms.php';

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

// Configure Africa's Talking credentials
$sms->setProviderCredentials('africastalking', null, [
    'username' => $_ENV['AFRICASTALKING_USERNAME'] ?? '',
    'api_key' => $_ENV['AFRICASTALKING_API_KEY'] ?? '',
], $_ENV['AFRICASTALKING_SENDER_ID'] ?? null);

$sms->setDefaultProvider('africastalking');

$result = $sms->send('+254712345678', 'Hello from Excelle SMS');

echo json_encode([
    'status' => $result->status->value,
    'local_id' => $result->localId,
], JSON_PRETTY_PRINT) . PHP_EOL;
