<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ExcelleInsights\Sms\Facade\SmsManager;
use ExcelleInsights\Sms\Support\DatabaseLogger;
use ExcelleInsights\Sms\Support\DefaultHttpClient;

$config = require __DIR__ . '/../config/sms.php';

$pdo = new PDO(
    $config['db_dsn'],
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$httpClient = new DefaultHttpClient();
$logger = new DatabaseLogger($pdo, $config['table_prefix'] ?? 'sms_');

$sms = new SmsManager($config, $pdo, $httpClient, $logger);

$provider = $_GET['provider'] ?? null;
$direction = $_GET['direction'] ?? null;

if (!$provider) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Provider parameter is required']);
    exit;
}

$headers = getallheaders() ?: [];
$payload = file_get_contents('php://input');

try {
    $sms->webhooks()->process($provider, [
        'headers' => $headers,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'url' => $_SERVER['REQUEST_URI'] ?? '',
        'query' => $_GET,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    ], $payload, $direction);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'accepted']);
} catch (\ExcelleInsights\Sms\Exceptions\WebhookVerificationException $e) {
    $logger->warning('Webhook verification failed', [
        'provider' => $provider,
        'error' => $e->getMessage(),
    ]);
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
} catch (Throwable $e) {
    $logger->error('Webhook processing failed', [
        'provider' => $provider,
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal error']);
}
