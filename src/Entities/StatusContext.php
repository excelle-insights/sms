<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class StatusContext
{
    public function __construct(
        public readonly mixed $actor,
        public readonly ?string $reason = null,
        public readonly ?\DateTimeInterface $scheduledAt = null,
        public readonly ?MessageStatus $expectedFrom = null,
        public readonly bool $atomic = false,
        public readonly bool $dryRun = false,
    ) {
    }
}
