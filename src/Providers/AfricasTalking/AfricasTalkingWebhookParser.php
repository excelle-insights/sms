<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\AfricasTalking;

use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;

class AfricasTalkingWebhookParser
{
    public function __construct(private readonly AfricasTalkingResponseMapper $mapper)
    {
    }

    /**
     * @param array<string, string> $payload
     */
    public function parseDeliveryReport(array $payload): ?DeliveryReport
    {
        $messageId = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;

        if ($messageId === null || $status === null) {
            return null;
        }

        return new DeliveryReport(
            providerMessageId: (string) $messageId,
            status: $this->mapper->mapDeliveryStatus($status),
            rawStatus: $status,
            errorCode: $payload['failureReason'] ?? null,
            errorMessage: $payload['failureReason'] ?? null,
            reportedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, string> $payload
     */
    public function parseInbound(array $payload): ?InboundMessage
    {
        $from = $payload['from'] ?? $payload['phoneNumber'] ?? null;
        $text = $payload['text'] ?? null;

        if ($from === null || $text === null) {
            return null;
        }

        return new InboundMessage(
            from: (string) $from,
            to: (string) ($payload['to'] ?? $payload['shortCode'] ?? ''),
            text: (string) $text,
            provider: 'africastalking',
            providerMessageId: $payload['id'] ?? null,
            receivedAt: isset($payload['date']) ? new \DateTimeImmutable((string) $payload['date']) : new \DateTimeImmutable(),
        );
    }
}
