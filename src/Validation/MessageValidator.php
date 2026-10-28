<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Validation;

use ExcelleInsights\Sms\Entities\RenderedMessage;

class MessageValidator
{
    public function __construct(private readonly int $maxSegments)
    {
    }

    public function validate(RenderedMessage $message): void
    {
        if ($message->body === '') {
            throw new \InvalidArgumentException('Message body cannot be empty');
        }

        if ($message->segments > $this->maxSegments) {
            throw new \InvalidArgumentException(
                "Message exceeds maximum of {$this->maxSegments} segments ({$message->segments} segments)",
            );
        }
    }
}
