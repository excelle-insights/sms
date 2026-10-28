<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class DeliveryReport
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly DeliveryStatus $status,
        public readonly ?string $rawStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?\DateTimeInterface $reportedAt = null,
    ) {
    }
}
