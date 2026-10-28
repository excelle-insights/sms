<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class SendRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $senderId = null,
        public readonly ?int $tenantId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?\DateTimeInterface $scheduledAt = null,
        public readonly array $options = [],
    ) {
    }
}
