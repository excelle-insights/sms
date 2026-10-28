<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Entities\Message;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\SendRequest;
use ExcelleInsights\Sms\Providers\ProviderRegistry;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\StatusChangeRepository;
use Psr\Log\LoggerInterface;

class WorkerService
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ProviderRegistry $providerRegistry,
        private readonly StatusChangeRepository $statusChangeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatchQueuedMessages(int $limit): int
    {
        $messages = $this->messageRepository->findQueued($limit);
        $dispatched = 0;

        foreach ($messages as $message) {
            if (!$this->claimProcessing($message)) {
                continue;
            }

            try {
                $providerKey = $message->provider;
                $messageId = $message->id;

                if ($providerKey === null || $messageId === null) {
                    throw new \RuntimeException('Message missing provider or id');
                }

                $provider = $this->providerRegistry->get($providerKey);
                $result = $provider->send(new SendRequest(
                    to: $message->to,
                    body: $message->body,
                    senderId: null,
                    tenantId: $message->tenantId,
                ));

                if ($result->isSuccess()) {
                    $this->messageRepository->markSent($messageId, $result->providerMessageId ?? '');
                    $this->recordChange($message, MessageStatus::PROCESSING, MessageStatus::SENT);
                } else {
                    $this->markFailed($message, $result->error ?? 'Unknown provider error');
                }

                ++$dispatched;
            } catch (\Throwable $e) {
                $this->logger->error('Dispatch failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
                $this->markFailed($message, $e->getMessage());
            }
        }

        return $dispatched;
    }

    public function releaseDueScheduledMessages(int $limit): int
    {
        $messages = $this->messageRepository->findDueScheduled($limit);

        foreach ($messages as $message) {
            $messageId = $message->id;

            if ($messageId === null) {
                continue;
            }

            $this->messageRepository->updateStatus($messageId, MessageStatus::QUEUED);
            $this->recordChange($message, MessageStatus::SCHEDULED, MessageStatus::QUEUED);
        }

        return count($messages);
    }

    /**
     * @param array<int, int> $retryDelays
     */
    public function retryFailedMessages(int $maxAttempts, array $retryDelays, int $limit): int
    {
        $messages = $this->messageRepository->findFailedRetryable($maxAttempts, $limit);
        $now = new \DateTimeImmutable();
        $retried = 0;

        foreach ($messages as $message) {
            $delayMinutes = $retryDelays[$message->attempts] ?? (int) array_slice($retryDelays, -1)[0];
            $lastUpdated = $message->updatedAt;

            if ($lastUpdated === null) {
                continue;
            }

            $nextRetry = \DateTimeImmutable::createFromInterface($lastUpdated)->modify("+{$delayMinutes} minutes");

            if ($nextRetry <= $now) {
                $messageId = $message->id;

                if ($messageId === null) {
                    continue;
                }

                $this->messageRepository->markRetryQueued($messageId, $message->attempts + 1);
                $this->recordChange($message, MessageStatus::FAILED, MessageStatus::QUEUED);
                ++$retried;
            }
        }

        return $retried;
    }

    private function claimProcessing(Message $message): bool
    {
        $messageId = $message->id;

        if ($messageId === null) {
            return false;
        }

        $claimed = $this->messageRepository->claimForProcessing($messageId);

        if ($claimed) {
            $this->recordChange($message, MessageStatus::QUEUED, MessageStatus::PROCESSING);
        }

        return $claimed;
    }

    private function markFailed(Message $message, string $error): void
    {
        $messageId = $message->id;

        if ($messageId === null) {
            return;
        }

        $this->messageRepository->markFailed($messageId, $error, $message->attempts + 1);
        $this->recordChange($message, MessageStatus::PROCESSING, MessageStatus::FAILED);
    }

    private function recordChange(Message $message, MessageStatus $from, MessageStatus $to): void
    {
        $this->statusChangeRepository->record(
            $message->id,
            $message->batchId,
            $from,
            $to,
            'worker',
            null,
            StatusTransitionPolicy::SOURCE_WORKER,
        );
    }
}
