<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\Celcom;

use ExcelleInsights\Sms\Contracts\HttpClientInterface;
use ExcelleInsights\Sms\Contracts\SmsProviderInterface;
use ExcelleInsights\Sms\Entities\Balance;
use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\ProviderCapabilities;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Entities\SendResult;

class CelcomProvider implements SmsProviderInterface
{
    public const KEY = 'celcom';

    /**
     * @param callable $configResolver fn(?int $tenantId): CelcomConfig
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CelcomResponseMapper $mapper,
        private $configResolver,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return 'Celcom Africa';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            bulk: true,
            balance: true,
        );
    }

    public function send(SendRequest $request): SendResult
    {
        $config = $this->resolveConfig($request->tenantId);
        $url = $config->baseUrl . '/sendsms/';

        $response = $this->httpClient->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'partnerID' => $config->partnerId,
                'apikey' => $config->apiKey,
                'mobile' => $this->formatNumber($request->to),
                'message' => $request->body,
                'shortcode' => $request->senderId ?? $config->senderId ?? '',
                'pass_type' => 'plain',
            ]),
        ]);

        $data = $this->decodeResponse($response->body);
        $code = (int) ($data['response-code'] ?? 500);

        if (!$this->mapper->isSuccessful($data)) {
            $error = $this->mapper->getErrorMessage($data);

            if ($this->mapper->isTransientErrorCode($code)) {
                return new SendResult(status: MessageStatus::FAILED, error: $error);
            }

            throw new \RuntimeException("Celcom permanent error: {$error}");
        }

        $recipients = $this->mapper->extractRecipients($data);
        $recipient = $recipients[0] ?? [];

        if (!$this->mapper->isRecipientSuccessful($recipient)) {
            $error = $this->mapper->getErrorMessage($recipient);
            $recipientCode = (int) ($recipient['respose-code'] ?? 500);

            if ($this->mapper->isTransientErrorCode($recipientCode)) {
                return new SendResult(status: MessageStatus::FAILED, error: $error);
            }

            throw new \RuntimeException("Celcom permanent error: {$error}");
        }

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: $this->mapper->getMessageId($recipient),
        );
    }

    /**
     * @param array<int, SendRequest> $requests
     *
     * @return array<int, SendResult>
     */
    public function sendBulk(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $config = $this->resolveConfig($requests[0]->tenantId);
        $url = $config->baseUrl . '/sendbulk';
        $smsList = [];

        foreach ($requests as $index => $request) {
            $smsList[] = [
                'partnerID' => $config->partnerId,
                'apikey' => $config->apiKey,
                'mobile' => $this->formatNumber($request->to),
                'message' => $request->body,
                'shortcode' => $request->senderId ?? $config->senderId ?? '',
                'pass_type' => 'plain',
                'clientsmsid' => (string) $index,
            ];
        }

        $response = $this->httpClient->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'count' => count($smsList),
                'smslist' => $smsList,
            ]),
        ]);

        $data = $this->decodeResponse($response->body);

        if (!$this->mapper->isSuccessful($data)) {
            throw new \RuntimeException('Celcom bulk send failed: ' . $this->mapper->getErrorMessage($data));
        }

        $recipients = $this->mapper->extractRecipients($data);
        $results = [];

        foreach ($recipients as $recipient) {
            if ($this->mapper->isRecipientSuccessful($recipient)) {
                $results[] = new SendResult(
                    status: MessageStatus::SENT,
                    providerMessageId: $this->mapper->getMessageId($recipient),
                );
            } else {
                $results[] = new SendResult(
                    status: MessageStatus::FAILED,
                    error: $this->mapper->getErrorMessage($recipient),
                );
            }
        }

        return $results;
    }

    public function getStatus(string $providerMessageId): ?DeliveryReport
    {
        return null;
    }

    public function parseDeliveryReport(array $headers, string $payload): ?DeliveryReport
    {
        return null;
    }

    public function parseInbound(array $headers, string $payload): ?InboundMessage
    {
        return null;
    }

    public function getBalance(): ?Balance
    {
        $config = $this->resolveConfig(null);
        $url = $config->baseUrl . '/getbalance/';

        $response = $this->httpClient->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'partnerID' => $config->partnerId,
                'apikey' => $config->apiKey,
            ]),
        ]);

        $data = $this->decodeResponse($response->body);

        return $this->mapper->mapBalance($data);
    }

    private function resolveConfig(?int $tenantId): CelcomConfig
    {
        $config = ($this->configResolver)($tenantId);

        if (!$config instanceof CelcomConfig) {
            throw new \RuntimeException('Celcom config resolver did not return a valid config');
        }

        if ($config->partnerId === '' || $config->apiKey === '') {
            throw new \RuntimeException('Celcom credentials are not configured');
        }

        return $config;
    }

    private function formatNumber(string $e164): string
    {
        return ltrim($e164, '+');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $body): array
    {
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
