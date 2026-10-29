<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use DateTimeImmutable;
use DateTimeZone;
use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Entities\Batch;
use ExcelleInsights\Sms\Entities\BatchResult;
use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\SendResult;
use ExcelleInsights\Sms\Entities\SmsTemplate;
use ExcelleInsights\Sms\Exceptions\TemplateNotFoundException;
use ExcelleInsights\Sms\Repositories\BatchRepository;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Repositories\ProviderConfigRepository;
use ExcelleInsights\Sms\Validation\MessageValidator;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;

class SendService
{
    public function __construct(
        private readonly SmsConfig $config,
        private readonly ProviderResolver $providerResolver,
        private readonly ProviderConfigRepository $providerConfigRepository,
        private readonly MessageRepository $messageRepository,
        private readonly BatchRepository $batchRepository,
        private readonly OptOutRepository $optOutRepository,
        private readonly TemplateService $templateService,
        private readonly PhoneNumberValidator $phoneValidator,
        private readonly MessageValidator $messageValidator,
        private readonly TemplateRenderer $templateRenderer,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $to, string $message, array $options = []): SendResult
    {
        $tenantId = $options['tenant_id'] ?? null;
        $provider = $this->providerResolver->resolve($options['provider'] ?? null, $tenantId);

        $normalisedTo = $this->phoneValidator->normalise($to);

        $templateKey = $options['template'] ?? null;
        $body = $message;
        $templateId = null;
        $templateVersion = null;
        $templateVariables = null;

        if ($templateKey !== null) {
            $template = $this->templateService->get((string) $templateKey, $tenantId);
            $variables = $options['template_vars'] ?? $options['vars'] ?? [];
            $rendered = $this->templateRenderer->preview($template->body, $variables);
            $body = $rendered->body;
            $templateId = $template->id;
            $templateVersion = $template->version;
            $templateVariables = $variables;
        }

        $rendered = $this->templateRenderer->analyse($body);
        $this->messageValidator->validate($rendered);

        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->messageRepository->findByIdempotencyKey(
                (string) $idempotencyKey,
                $tenantId,
                $this->config->idempotencyKeyTtlHours(),
            );

            if ($existing !== null) {
                return new SendResult(
                    status: $existing->status,
                    localId: $existing->id,
                    providerMessageId: $existing->providerMessageId,
                );
            }
        }

        $category = $templateKey !== null
            ? $this->getTemplateCategory((string) $templateKey, $tenantId)
            : ($options['category'] ?? SmsTemplate::CATEGORY_MARKETING);

        $bypassOptOut = (bool) ($options['bypass_opt_out'] ?? false);
        if ($category === SmsTemplate::CATEGORY_MARKETING && !$bypassOptOut) {
            if ($this->optOutRepository->exists($normalisedTo, $tenantId)) {
                throw new \RuntimeException("Recipient {$normalisedTo} has opted out");
            }
        }

        $senderId = $options['sender_id']
            ?? $this->providerConfigRepository->getSenderId($provider, $tenantId)
            ?? null;

        $scheduledAt = $this->resolveScheduledAt($options);
        $status = $this->resolveInitialStatus($options);
        $origin = (string) ($options['origin'] ?? 'campaign');
        $priority = (int) ($options['priority'] ?? 5);

        $messageEntity = new Message(
            tenantId: $tenantId,
            provider: $provider,
            to: $normalisedTo,
            body: $rendered->body,
            encoding: $rendered->encoding,
            segments: $rendered->segments,
            status: $status,
            templateId: $templateId,
            templateVersion: $templateVersion,
            templateVariables: $templateVariables,
            scheduledAt: $scheduledAt,
            idempotencyKey: $idempotencyKey === '' ? null : $idempotencyKey,
            origin: $origin,
            priority: $priority,
            metadata: $options['metadata'] ?? null,
        );

        $created = $this->messageRepository->create($messageEntity);

        return new SendResult(
            status: $created->status,
            localId: $created->id,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @param array<string, mixed> $options
     */
    public function sendBulk(array $recipients, string $message, array $options = []): BatchResult
    {
        $tenantId = $options['tenant_id'] ?? null;
        $provider = $this->providerResolver->resolve($options['provider'] ?? null, $tenantId);
        $templateKey = $options['template'] ?? null;
        $sharedBody = $templateKey === null ? $message : null;
        $senderId = $options['sender_id']
            ?? $this->providerConfigRepository->getSenderId($provider, $tenantId)
            ?? null;
        $scheduledAt = $this->resolveScheduledAt($options);
        $initialStatus = $this->resolveInitialStatus($options);

        $batch = $this->batchRepository->create(new Batch(
            tenantId: $tenantId,
            status: $initialStatus,
            total: count($recipients),
        ));

        if ($batch->id === null) {
            throw new \RuntimeException('Failed to create batch');
        }

        $queued = 0;
        $failed = 0;
        $failures = [];

        foreach ($recipients as $recipient) {
            try {
                $to = $recipient['to'] ?? $recipient['phone'] ?? null;

                if ($to === null) {
                    throw new \InvalidArgumentException('Recipient missing phone number');
                }

                $normalisedTo = $this->phoneValidator->normalise((string) $to);
                $body = $sharedBody ?? $this->renderRecipientBody($message, $recipient, $tenantId);
                $rendered = $this->templateRenderer->analyse($body);
                $this->messageValidator->validate($rendered);

                $category = $templateKey !== null
                    ? $this->getTemplateCategory((string) $templateKey, $tenantId)
                    : ($options['category'] ?? SmsTemplate::CATEGORY_MARKETING);

                $bypassOptOut = (bool) ($recipient['bypass_opt_out'] ?? $options['bypass_opt_out'] ?? false);
                if ($category === SmsTemplate::CATEGORY_MARKETING && !$bypassOptOut) {
                    if ($this->optOutRepository->exists($normalisedTo, $tenantId)) {
                        throw new \RuntimeException("Recipient {$normalisedTo} has opted out");
                    }
                }

                $origin = (string) ($recipient['origin'] ?? $options['origin'] ?? 'campaign');
                $priority = (int) ($recipient['priority'] ?? $options['priority'] ?? 5);

                $messageEntity = new Message(
                    tenantId: $tenantId,
                    batchId: $batch->id,
                    provider: $provider,
                    to: $normalisedTo,
                    body: $rendered->body,
                    encoding: $rendered->encoding,
                    segments: $rendered->segments,
                    status: $initialStatus,
                    scheduledAt: $scheduledAt,
                    origin: $origin,
                    priority: $priority,
                    metadata: $options['metadata'] ?? null,
                );

                $this->messageRepository->create($messageEntity);
                ++$queued;
            } catch (\Throwable $e) {
                ++$failed;
                $failures[] = [
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->batchRepository->incrementCounters($batch->id, [
            'queued' => $queued,
            'failed' => $failed,
        ]);

        return new BatchResult(
            batchId: $batch->id,
            total: count($recipients),
            queued: $queued,
            failed: $failed,
            failures: $failures,
        );
    }

    /**
     * @param array<string, mixed> $recipient
     */
    private function renderRecipientBody(string $templateKey, array $recipient, ?int $tenantId): string
    {
        $template = $this->templateService->get($templateKey, $tenantId);
        $variables = $recipient['vars'] ?? $recipient['template_vars'] ?? [];

        return $this->templateRenderer->render($template->body, $variables);
    }

    private function getTemplateCategory(string $key, ?int $tenantId): string
    {
        try {
            $template = $this->templateService->get($key, $tenantId);

            return $template->category;
        } catch (TemplateNotFoundException) {
            return SmsTemplate::CATEGORY_MARKETING;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveScheduledAt(array $options): ?DateTimeImmutable
    {
        $scheduled = $options['scheduled_at'] ?? null;

        if ($scheduled === null) {
            return null;
        }

        if ($scheduled instanceof \DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($scheduled);
        } else {
            $date = new DateTimeImmutable((string) $scheduled);
        }

        $timezone = $options['timezone'] ?? null;
        if ($timezone !== null) {
            $date = $date->setTimezone(new DateTimeZone((string) $timezone));
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveInitialStatus(array $options): MessageStatus
    {
        if ((bool) ($options['pending'] ?? false)) {
            return MessageStatus::PENDING;
        }

        if (isset($options['scheduled_at']) && $options['scheduled_at'] !== null) {
            return MessageStatus::SCHEDULED;
        }

        return MessageStatus::QUEUED;
    }
}
