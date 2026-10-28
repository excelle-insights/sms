<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class InboundMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $text,
        public readonly string $provider,
        public readonly ?string $providerMessageId = null,
        public readonly ?\DateTimeInterface $receivedAt = null,
        public readonly ?int $tenantId = null,
    ) {
    }
}
