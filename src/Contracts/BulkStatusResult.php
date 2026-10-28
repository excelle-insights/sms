<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Contracts;

use ExcelleInsights\Sms\Entities\StatusResult;

class BulkStatusResult
{
    /**
     * @param array<int, StatusResult> $results
     */
    public function __construct(
        public readonly array $results,
        public readonly int $changed,
        public readonly int $unchanged,
        public readonly int $failed,
    ) {
    }
}
