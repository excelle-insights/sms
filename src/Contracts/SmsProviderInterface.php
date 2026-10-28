<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Contracts;

use ExcelleInsights\Sms\Entities\Balance;
use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;
use ExcelleInsights\Sms\Entities\ProviderCapabilities;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Entities\SendResult;

interface SmsProviderInterface
{
    /**
     * Unique provider key used in the registry and database.
     */
    public function getKey(): string;

    /**
     * Human-readable provider name.
     */
    public function getDisplayName(): string;

    /**
     * Describes what this provider supports.
     */
    public function capabilities(): ProviderCapabilities;

    /**
     * Send a single SMS.
     */
    public function send(SendRequest $request): SendResult;

    /**
     * Send multiple SMS in one provider call.
     *
     * @param array<int, SendRequest> $requests
     *
     * @return array<int, SendResult>
     */
    public function sendBulk(array $requests): array;

    /**
     * Query the provider for the current status of a message.
     */
    public function getStatus(string $providerMessageId): ?DeliveryReport;

    /**
     * Parse a provider delivery-report webhook payload.
     *
     * @param array<string, string> $headers
     */
    public function parseDeliveryReport(array $headers, string $payload): ?DeliveryReport;

    /**
     * Parse a provider inbound-SMS webhook payload.
     *
     * @param array<string, string> $headers
     */
    public function parseInbound(array $headers, string $payload): ?InboundMessage;

    /**
     * Query the provider account balance.
     */
    public function getBalance(): ?Balance;
}
