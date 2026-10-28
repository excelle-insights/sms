<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

class ProviderCapabilities
{
    public function __construct(
        public readonly bool $bulk = false,
        public readonly bool $nativeScheduling = false,
        public readonly bool $inbound = false,
        public readonly bool $balance = false,
        public readonly bool $deliveryReportWebhook = false,
        public readonly bool $deliveryReportPolling = false,
    ) {
    }
}
