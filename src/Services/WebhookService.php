<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Entities\DeliveryReport;
use ExcelleInsights\Sms\Entities\InboundMessage;
use ExcelleInsights\Sms\Exceptions\WebhookVerificationException;
use ExcelleInsights\Sms\Providers\ProviderRegistry;
use ExcelleInsights\Sms\Repositories\DeliveryReportRepository;
use ExcelleInsights\Sms\Repositories\InboundMessageRepository;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Repositories\WebhookLogRepository;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;
use Psr\Log\LoggerInterface;

class WebhookService
{
    /**
     * @var array<int, string>
     */
    private const OPT_OUT_KEYWORDS = ['stop', 'unsubscribe', 'opt out', 'opt-out', 'cancel'];

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly SmsConfig $config,
        private readonly WebhookLogRepository $webhookLogRepository,
        private readonly InboundMessageRepository $inboundMessageRepository,
        private readonly DeliveryReportRepository $deliveryReportRepository,
        private readonly MessageRepository $messageRepository,
        private readonly OptOutRepository $optOutRepository,
        private readonly PhoneNumberValidator $phoneValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process a provider webhook.
     *
     * @param array<string, mixed> $requestData
     */
    public function process(string $provider, array $requestData, string $payload, ?string $direction = null): void
    {
        $this->verify($provider, $requestData);

        $providerInstance = $this->providerRegistry->get($provider);
        $headers = $this->normaliseHeaders($requestData['headers'] ?? []);

        if ($direction === 'dlr' || $direction === null) {
            $report = $providerInstance->parseDeliveryReport($headers, $payload);

            if ($report !== null) {
                $this->logWebhook($provider, 'dlr', $headers, $payload);
                $this->handleDeliveryReport($provider, $report);

                return;
            }
        }

        if ($direction === 'inbound' || $direction === null) {
            $inbound = $providerInstance->parseInbound($headers, $payload);

            if ($inbound !== null) {
                $this->logWebhook($provider, 'inbound', $headers, $payload);
                $this->handleInbound($provider, $inbound);

                return;
            }
        }

        $this->logWebhook($provider, 'unknown', $headers, $payload);
        throw new \RuntimeException('Webhook payload could not be parsed for provider ' . $provider);
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function verify(string $provider, array $requestData): void
    {
        $providerConfig = $this->config->providerConfig($provider);
        $secret = $providerConfig['webhook_secret'] ?? null;
        $allowedIps = $providerConfig['webhook_allowed_ips'] ?? [];

        if ($secret !== null && $secret !== '') {
            $receivedSecret = $this->extractSecret($requestData);

            if (!hash_equals((string) $secret, $receivedSecret)) {
                throw new WebhookVerificationException('Invalid webhook secret');
            }

            return;
        }

        if ($allowedIps !== []) {
            $remoteIp = $requestData['remote_addr'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

            if ($remoteIp === null || !in_array((string) $remoteIp, (array) $allowedIps, true)) {
                throw new WebhookVerificationException('Webhook origin not allowed');
            }

            return;
        }

        // No verification configured; accept the webhook.
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function extractSecret(array $requestData): string
    {
        $query = $requestData['query'] ?? [];

        if (isset($query['secret']) && is_string($query['secret'])) {
            return $query['secret'];
        }

        $headers = $this->normaliseHeaders($requestData['headers'] ?? []);

        return $headers['x-webhook-secret'] ?? '';
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $key => $value) {
            if (is_string($value)) {
                $normalised[strtolower((string) $key)] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @param array<string, string> $headers
     */
    private function logWebhook(string $provider, string $direction, array $headers, string $payload): void
    {
        try {
            $this->webhookLogRepository->create($provider, $direction, $headers, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to log webhook', ['error' => $e->getMessage()]);
        }
    }

    private function handleDeliveryReport(string $provider, DeliveryReport $report): void
    {
        $message = $this->messageRepository->findByProviderMessageId($report->providerMessageId, $provider);
        $messageId = $message?->id;

        $this->deliveryReportRepository->create($report, $messageId);

        if ($messageId !== null) {
            $this->messageRepository->updateDeliveryStatus($messageId, $report->status->value);
        }
    }

    private function handleInbound(string $provider, InboundMessage $inbound): void
    {
        $message = new InboundMessage(
            from: $this->phoneValidator->normalise($inbound->from),
            to: $inbound->to,
            text: $inbound->text,
            provider: $provider,
            providerMessageId: $inbound->providerMessageId,
            receivedAt: $inbound->receivedAt,
            tenantId: $inbound->tenantId,
        );

        $this->inboundMessageRepository->create($message);

        if ($this->isOptOutRequest($inbound->text)) {
            $this->optOutRepository->add($message->from, $message->tenantId, 'inbound:' . $provider);
        }
    }

    private function isOptOutRequest(string $text): bool
    {
        $normalised = strtolower(trim($text));

        foreach (self::OPT_OUT_KEYWORDS as $keyword) {
            if ($normalised === $keyword || str_starts_with($normalised, $keyword . ' ')) {
                return true;
            }
        }

        return false;
    }
}
