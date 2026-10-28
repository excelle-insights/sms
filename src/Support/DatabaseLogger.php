<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

use PDO;
use Psr\Log\AbstractLogger;

class DatabaseLogger extends AbstractLogger
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix = 'sms_',
    ) {
        $this->table = $tablePrefix . 'logs';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (level, message, context, created_at) VALUES (:level, :message, :context, NOW())",
        );

        $stmt->execute([
            ':level' => $level,
            ':message' => (string) $message,
            ':context' => json_encode($context),
        ]);
    }
}
