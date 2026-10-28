<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class StatusResult
{
    public const CHANGED = 'changed';
    public const UNCHANGED = 'unchanged';
    public const INVALID_TRANSITION = 'invalid_transition';
    public const NOT_FOUND = 'not_found';
    public const STALE_STATE = 'stale_state';
    public const DENIED = 'denied';

    public function __construct(
        public readonly int $messageId,
        public readonly string $outcome,
        public readonly ?MessageStatus $from = null,
        public readonly ?MessageStatus $to = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome === self::CHANGED || $this->outcome === self::UNCHANGED;
    }
}
