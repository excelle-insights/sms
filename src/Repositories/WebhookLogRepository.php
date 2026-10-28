<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use PDO;

class WebhookLogRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'webhook_logs';
    }

    /**
     * @param array<string, string> $headers
     */
    public function create(
        string $provider,
        string $direction,
        array $headers,
        string $payload,
        ?int $tenantId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, provider, direction, headers, payload, created_at)
             VALUES (:tenant_id, :provider, :direction, :headers, :payload, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider' => $provider,
            ':direction' => $direction,
            ':headers' => json_encode($headers),
            ':payload' => $payload,
        ]);
    }
}
