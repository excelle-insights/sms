<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\DeliveryStatus;
use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use PDO;

class MessageRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'messages';
    }

    public function create(Message $message): Message
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, batch_id, provider, provider_message_id, `to`, body, encoding, segments,
              status, delivery_status, approved_by, approved_at, rejected_reason,
              template_id, template_version, template_variables, scheduled_at, attempts,
              idempotency_key, error, metadata, created_at, updated_at)
             VALUES
             (:tenant_id, :batch_id, :provider, :provider_message_id, :to, :body, :encoding, :segments,
              :status, :delivery_status, :approved_by, :approved_at, :rejected_reason,
              :template_id, :template_version, :template_variables, :scheduled_at, :attempts,
              :idempotency_key, :error, :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $message->tenantId,
            ':batch_id' => $message->batchId,
            ':provider' => $message->provider,
            ':provider_message_id' => $message->providerMessageId,
            ':to' => $message->to,
            ':body' => $message->body,
            ':encoding' => $message->encoding,
            ':segments' => $message->segments,
            ':status' => $message->status->value,
            ':delivery_status' => $message->deliveryStatus->value,
            ':approved_by' => $message->approvedBy ?? null,
            ':approved_at' => $this->formatDateTime($message->approvedAt),
            ':rejected_reason' => $message->rejectedReason ?? null,
            ':template_id' => $message->templateId ?? null,
            ':template_version' => $message->templateVersion ?? null,
            ':template_variables' => $message->templateVariables === null ? null : json_encode($message->templateVariables),
            ':scheduled_at' => $this->formatDateTime($message->scheduledAt),
            ':attempts' => $message->attempts,
            ':idempotency_key' => $message->idempotencyKey,
            ':error' => $message->error,
            ':metadata' => $message->metadata === null ? null : json_encode($message->metadata),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $created = $this->findById($id);

        if ($created === null) {
            throw new \RuntimeException('Failed to create message');
        }

        return $created;
    }

    public function findById(int $id): ?Message
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByProviderMessageId(string $providerMessageId, string $provider): ?Message
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE provider_message_id = :provider_message_id AND provider = :provider LIMIT 1",
        );

        $stmt->execute([
            ':provider_message_id' => $providerMessageId,
            ':provider' => $provider,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function updateDeliveryStatus(int $id, string $deliveryStatus): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET delivery_status = :delivery_status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );

        $stmt->execute([':delivery_status' => $deliveryStatus, ':id' => $id]);
    }

    /**
     * @return array<int, int>
     */
    public function findIdsByBatchId(int $batchId): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function findByIdempotencyKey(string $key, ?int $tenantId, int $ttlHours): ?Message
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE {$where} AND idempotency_key = :key
             ORDER BY created_at DESC
             LIMIT 1",
        );

        $stmt->execute(array_merge($params, [':key' => $key]));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $createdAt = $this->parseDateTime($row['created_at']);
        $cutoff = (new \DateTimeImmutable())->modify("-{$ttlHours} hours");

        if ($createdAt === null || $createdAt < $cutoff) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<int, Message>
     */
    public function findDueScheduled(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = :status AND scheduled_at <= CURRENT_TIMESTAMP
             ORDER BY scheduled_at ASC
             LIMIT :limit",
        );

        $stmt->execute([':status' => MessageStatus::SCHEDULED->value, ':limit' => $limit]);

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, Message>
     */
    public function findQueued(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = :status
             ORDER BY created_at ASC
             LIMIT :limit",
        );

        $stmt->execute([':status' => MessageStatus::QUEUED->value, ':limit' => $limit]);

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, Message>
     */
    public function findFailedRetryable(int $maxAttempts, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = :status AND attempts < :max_attempts
             ORDER BY updated_at ASC
             LIMIT :limit",
        );

        $stmt->execute([
            ':status' => MessageStatus::FAILED->value,
            ':max_attempts' => $maxAttempts,
            ':limit' => $limit,
        ]);

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Optimistically claim a queued message for processing.
     */
    public function claimForProcessing(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = :processing, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :queued",
        );

        $stmt->execute([
            ':processing' => MessageStatus::PROCESSING->value,
            ':id' => $id,
            ':queued' => MessageStatus::QUEUED->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markSent(int $id, string $providerMessageId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = :sent, provider_message_id = :provider_message_id, error = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );

        $stmt->execute([
            ':sent' => MessageStatus::SENT->value,
            ':provider_message_id' => $providerMessageId,
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error, int $attempts): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = :failed, error = :error, attempts = :attempts, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );

        $stmt->execute([
            ':failed' => MessageStatus::FAILED->value,
            ':error' => $error,
            ':attempts' => $attempts,
            ':id' => $id,
        ]);
    }

    public function markRetryQueued(int $id, int $attempts): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = :queued, attempts = :attempts, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );

        $stmt->execute([
            ':queued' => MessageStatus::QUEUED->value,
            ':attempts' => $attempts,
            ':id' => $id,
        ]);
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
    private function mapRow(array $row): Message
    {
        return new Message(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            provider: (string) $row['provider'],
            providerMessageId: $row['provider_message_id'] === null ? null : (string) $row['provider_message_id'],
            to: (string) $row['to'],
            body: (string) $row['body'],
            status: MessageStatus::from((string) $row['status']),
            deliveryStatus: DeliveryStatus::from((string) $row['delivery_status']),
            scheduledAt: $this->parseDateTime($row['scheduled_at'] ?? null),
            attempts: (int) $row['attempts'],
            idempotencyKey: $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
            error: $row['error'] === null ? null : (string) $row['error'],
            createdAt: $this->parseDateTime($row['created_at']),
            updatedAt: $this->parseDateTime($row['updated_at']),
        );
    }

    private function formatDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime === null ? null : $dateTime->format('Y-m-d H:i:s');
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : new \DateTimeImmutable($value);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tenantWhere(string $column, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return ["{$column} IS NULL", []];
        }

        return ["{$column} = :tenant_id", [':tenant_id' => $tenantId]];
    }
}
