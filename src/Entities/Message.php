<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class Message
{
    /**
     * @param array<string, mixed>|null $templateVariables
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly ?int $batchId = null,
        public readonly ?string $provider = null,
        public readonly ?string $providerMessageId = null,
        public readonly string $to = '',
        public readonly string $body = '',
        public readonly string $encoding = 'gsm7',
        public readonly int $segments = 1,
        public readonly MessageStatus $status = MessageStatus::QUEUED,
        public readonly DeliveryStatus $deliveryStatus = DeliveryStatus::NONE,
        public readonly ?string $approvedBy = null,
        public readonly ?\DateTimeInterface $approvedAt = null,
        public readonly ?string $rejectedReason = null,
        public readonly ?int $templateId = null,
        public readonly ?int $templateVersion = null,
        public readonly ?array $templateVariables = null,
        public readonly ?\DateTimeInterface $scheduledAt = null,
        public readonly int $attempts = 0,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $error = null,
        public readonly string $origin = 'campaign',
        public readonly int $priority = 5,
        public readonly ?array $metadata = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {
    }
}
