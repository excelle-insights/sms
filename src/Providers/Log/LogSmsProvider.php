<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\Log;

use ExcelleInsights\Sms\Contracts\SmsProviderInterface;
use ExcelleInsights\Sms\Entities\Balance;
use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\ProviderCapabilities;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Entities\SendResult;
use Psr\Log\LoggerInterface;

class LogSmsProvider implements SmsProviderInterface
{
    public const KEY = 'log';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return 'Log / Null Provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            bulk: true,
            inbound: true,
            balance: true,
            deliveryReportWebhook: true,
        );
    }

    public function send(SendRequest $request): SendResult
    {
        $this->logger->info('[SMS] Would send message', [
            'to' => $request->to,
            'body' => $request->body,
            'sender_id' => $request->senderId,
        ]);

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: 'log-' . uniqid(),
        );
    }

    /**
     * @param array<int, SendRequest> $requests
     *
     * @return array<int, SendResult>
     */
    public function sendBulk(array $requests): array
    {
        $results = [];
        foreach ($requests as $request) {
            $results[] = $this->send($request);
        }

        return $results;
    }

    public function getStatus(string $providerMessageId): ?DeliveryReport
    {
        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseDeliveryReport(array $headers, string $payload): ?DeliveryReport
    {
        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseInbound(array $headers, string $payload): ?InboundMessage
    {
        return null;
    }

    public function getBalance(): ?Balance
    {
        return new Balance(amount: 0.0, currency: 'SMS');
    }
}
