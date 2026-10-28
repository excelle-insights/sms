<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class SendResult
{
    public function __construct(
        public readonly MessageStatus $status,
        public readonly ?int $localId = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === MessageStatus::SENT || $this->status === MessageStatus::QUEUED;
    }
}
