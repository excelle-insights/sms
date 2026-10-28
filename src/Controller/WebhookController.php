<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Controller;

use ExcelleInsights\Sms\Services\WebhookService;

class WebhookController
{
    public function __construct(private readonly WebhookService $webhookService)
    {
    }

    /**
     * Process a webhook request.
     *
     * @param array<string, mixed> $requestData
     */
    public function process(string $provider, array $requestData, string $payload, ?string $direction = null): void
    {
        $this->webhookService->process($provider, $requestData, $payload, $direction);
    }
}
