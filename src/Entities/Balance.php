<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class Balance
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $rawResponse = null,
    ) {
    }
}
