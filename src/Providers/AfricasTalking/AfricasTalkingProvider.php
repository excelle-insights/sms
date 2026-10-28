<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\AfricasTalking;

use ExcelleInsights\Sms\Contracts\HttpClientInterface;
use ExcelleInsights\Sms\Contracts\SmsProviderInterface;
use ExcelleInsights\Sms\Entities\Balance;
use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\ProviderCapabilities;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Entities\SendResult;

class AfricasTalkingProvider implements SmsProviderInterface
{
    public const KEY = 'africastalking';

    /**
     * @param callable $configResolver fn(?int $tenantId): AfricasTalkingConfig
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AfricasTalkingResponseMapper $mapper,
        private readonly AfricasTalkingWebhookParser $webhookParser,
        private $configResolver,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return "Africa's Talking";
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
        $config = $this->resolveConfig($request->tenantId);
        $url = $config->baseUrl() . '/messaging';

        $response = $this->httpClient->post($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'apiKey' => $config->apiKey,
            ],
            'body' => http_build_query([
                'username' => $config->username,
                'to' => $request->to,
                'message' => $request->body,
                'from' => $request->senderId ?? $config->senderId ?? null,
            ]),
        ]);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException("Africa's Talking send failed: {$response->body}");
        }

        $data = $this->decodeResponse($response->body);
        $recipients = $this->mapper->extractRecipients($data);
        $recipient = $recipients[0] ?? null;

        if ($recipient === null) {
            throw new \RuntimeException("Africa's Talking response missing recipient data");
        }

        $statusCode = (int) ($recipient['statusCode'] ?? 0);
        $error = $recipient['status'] ?? 'Unknown error';

        if (!$this->mapper->isRecipientSuccessful($recipient)) {
            if ($this->mapper->isTransientError($statusCode)) {
                return new SendResult(
                    status: MessageStatus::FAILED,
                    error: $error,
                );
            }

            throw new \RuntimeException("Africa's Talking permanent error: {$error}");
        }

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: (string) ($recipient['messageId'] ?? ''),
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
        $url = $config->baseUrl() . '/messaging';
        $recipients = [];

        foreach ($requests as $request) {
            $recipients[] = $request->to;
        }

        $response = $this->httpClient->post($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'apiKey' => $config->apiKey,
            ],
            'body' => http_build_query([
                'username' => $config->username,
                'to' => implode(',', $recipients),
                'message' => $requests[0]->body,
                'from' => $requests[0]->senderId ?? $config->senderId ?? null,
            ]),
        ]);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException("Africa's Talking bulk send failed: {$response->body}");
        }

        $data = $this->decodeResponse($response->body);
        $recipientResponses = $this->mapper->extractRecipients($data);
        $results = [];

        foreach ($recipientResponses as $recipient) {
            $statusCode = (int) ($recipient['statusCode'] ?? 0);

            if ($this->mapper->isRecipientSuccessful($recipient)) {
                $results[] = new SendResult(
                    status: MessageStatus::SENT,
                    providerMessageId: (string) ($recipient['messageId'] ?? ''),
                );
            } else {
                $results[] = new SendResult(
                    status: MessageStatus::FAILED,
                    error: $recipient['status'] ?? 'Unknown error',
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
        $data = $this->parsePayload($payload);

        return $this->webhookParser->parseDeliveryReport($data);
    }

    public function parseInbound(array $headers, string $payload): ?InboundMessage
    {
        $data = $this->parsePayload($payload);

        return $this->webhookParser->parseInbound($data);
    }

    /**
     * @return array<string, string>
     */
    private function parsePayload(string $payload): array
    {
        $parsed = [];
        parse_str($payload, $parsed);

        $flat = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $flat[(string) $key] = $value;
            }
        }

        return $flat;
    }

    public function getBalance(): ?Balance
    {
        $config = $this->resolveConfig(null);
        $url = $config->baseUrl() . '/user/stop?username=' . urlencode($config->username);

        $response = $this->httpClient->get($url, [
            'headers' => [
                'Accept' => 'application/json',
                'apiKey' => $config->apiKey,
            ],
        ]);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException("Africa's Talking balance check failed: {$response->body}");
        }

        return $this->mapper->mapBalance($this->decodeResponse($response->body));
    }

    private function resolveConfig(?int $tenantId): AfricasTalkingConfig
    {
        $config = ($this->configResolver)($tenantId);

        if (!$config instanceof AfricasTalkingConfig) {
            throw new \RuntimeException("Africa's Talking config resolver did not return a valid config");
        }

        return $config;
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
