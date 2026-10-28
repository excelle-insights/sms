<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class BatchResult
{
    /**
     * @param array<int, array<string, mixed>> $failures
     */
    public function __construct(
        public readonly int $batchId,
        public readonly int $total,
        public readonly int $queued,
        public readonly int $failed,
        public readonly array $failures = [],
    ) {
    }
}
