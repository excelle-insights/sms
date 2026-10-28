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

$lockFile = $config['worker_lock_file'] ?? __DIR__ . '/../storage/sms-worker.lock';
$lock = fopen($lockFile, 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "sms-worker is already running.\n");
    exit(1);
}

$runFor = (int) ($config['worker_run_seconds'] ?? 55);
$batchSize = (int) ($config['worker_batch_size'] ?? 100);
$sleep = (int) ($config['worker_sleep_seconds'] ?? 5);
$stopAt = time() + $runFor;

$logger->info('sms-worker started');

try {
    while (time() < $stopAt) {
        $sms->worker()->releaseDueScheduledMessages($batchSize);
        $sms->worker()->dispatchQueuedMessages($batchSize);
        $sms->worker()->retryFailedMessages($batchSize);

        sleep($sleep);
    }
} catch (Throwable $e) {
    $logger->error('sms-worker error: ' . $e->getMessage(), ['exception' => $e]);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
    $logger->info('sms-worker finished');
}
