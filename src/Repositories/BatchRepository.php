<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\Batch;
use ExcelleInsights\Sms\Entities\MessageStatus;
use PDO;

class BatchRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'batches';
    }

    public function create(Batch $batch): Batch
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, status, total, queued, sent, delivered, failed, metadata, created_at, updated_at)
             VALUES (:tenant_id, :status, :total, :queued, :sent, :delivered, :failed, :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $batch->tenantId,
            ':status' => $batch->status->value,
            ':total' => $batch->total,
            ':queued' => $batch->queued,
            ':sent' => $batch->sent,
            ':delivered' => $batch->delivered,
            ':failed' => $batch->failed,
            ':metadata' => $batch->metadata === null ? null : json_encode($batch->metadata),
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function find(int $id): Batch
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Batch {$id} not found");
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, int> $changes
     */
    public function incrementCounters(int $id, array $changes): void
    {
        $parts = [];
        $params = [':id' => $id];

        foreach ($changes as $column => $delta) {
            $param = ":{$column}";
            $parts[] = "{$column} = {$column} + {$param}";
            $params[$param] = $delta;
        }

        $parts[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = "UPDATE {$this->table} SET " . implode(', ', $parts) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updateStatus(int $id, MessageStatus $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );

        $stmt->execute([':status' => $status->value, ':id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Batch
    {
        return new Batch(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            status: MessageStatus::from((string) $row['status']),
            total: (int) $row['total'],
            queued: (int) $row['queued'],
            sent: (int) $row['sent'],
            delivered: (int) $row['delivered'],
            failed: (int) $row['failed'],
            metadata: $row['metadata'] === null ? null : json_decode((string) $row['metadata'], true),
            createdAt: $this->parseDateTime($row['created_at']),
            updatedAt: $this->parseDateTime($row['updated_at']),
        );
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : new \DateTimeImmutable($value);
    }
}
