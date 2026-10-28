<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class Batch
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly MessageStatus $status = MessageStatus::QUEUED,
        public readonly int $total = 0,
        public readonly int $queued = 0,
        public readonly int $sent = 0,
        public readonly int $delivered = 0,
        public readonly int $failed = 0,
        public readonly ?array $metadata = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {
    }
}
