<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class RenderedMessage
{
    public function __construct(
        public readonly string $body,
        public readonly string $encoding,
        public readonly int $segments,
    ) {
    }
}
