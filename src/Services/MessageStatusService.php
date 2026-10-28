<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Contracts\ApprovalAuthorizerInterface;
use ExcelleInsights\Sms\Contracts\BulkStatusResult;
use ExcelleInsights\Sms\Contracts\MessageStatusServiceInterface;
use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\StatusContext;
use ExcelleInsights\Sms\Entities\StatusResult;
use ExcelleInsights\Sms\Repositories\MessageRepository;
use ExcelleInsights\Sms\Repositories\StatusChangeRepository;

class MessageStatusService implements MessageStatusServiceInterface
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly StatusChangeRepository $statusChangeRepository,
        private readonly StatusTransitionPolicy $policy,
        private readonly ?ApprovalAuthorizerInterface $authorizer = null,
    ) {
    }

    public function change(int $messageId, MessageStatus $to, StatusContext $ctx): StatusResult
    {
        return $this->performChange($messageId, $to, $ctx, StatusTransitionPolicy::SOURCE_APPLICATION);
    }

    /**
     * @param array<int, int> $messageIds
     */
    public function changeMany(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult
    {
        $results = [];
        $changed = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($messageIds as $messageId) {
            $result = $this->change($messageId, $to, $ctx);
            $results[] = $result;

            if ($result->outcome === StatusResult::CHANGED) {
                ++$changed;
            } elseif ($result->outcome === StatusResult::UNCHANGED) {
                ++$unchanged;
            } else {
                ++$failed;
            }
        }

        return new BulkStatusResult($results, $changed, $unchanged, $failed);
    }

    public function changeBatch(int $batchId, MessageStatus $to, StatusContext $ctx): BulkStatusResult
    {
        $messageIds = $this->messageRepository->findIdsByBatchId($batchId);

        return $this->changeMany($messageIds, $to, $ctx);
    }

    public function allowedTransitions(int $messageId): array
    {
        $message = $this->messageRepository->findById($messageId);

        if ($message === null) {
            return [];
        }

        return $this->policy->allowedTransitions($message->status, StatusTransitionPolicy::SOURCE_APPLICATION);
    }

    public function approve(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::QUEUED, $ctx);
    }

    public function reject(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::REJECTED, $ctx);
    }

    public function cancel(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::CANCELLED, $ctx);
    }

    public function reschedule(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::SCHEDULED, $ctx);
    }

    public function retry(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::QUEUED, $ctx);
    }

    public function resubmit(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::PENDING, $ctx);
    }

    public function workerChange(int $messageId, MessageStatus $to): StatusResult
    {
        return $this->performChange(
            $messageId,
            $to,
            new StatusContext(actor: 'worker'),
            StatusTransitionPolicy::SOURCE_WORKER,
        );
    }

    private function performChange(
        int $messageId,
        MessageStatus $to,
        StatusContext $ctx,
        string $source,
    ): StatusResult {
        $message = $this->messageRepository->findById($messageId);

        if ($message === null) {
            return new StatusResult($messageId, StatusResult::NOT_FOUND);
        }

        if ($message->status === $to) {
            return new StatusResult(
                $messageId,
                StatusResult::UNCHANGED,
                from: $message->status,
                to: $to,
            );
        }

        if ($ctx->expectedFrom !== null && $message->status !== $ctx->expectedFrom) {
            return new StatusResult(
                $messageId,
                StatusResult::STALE_STATE,
                from: $message->status,
                to: $to,
                error: 'Message is no longer in expected status',
            );
        }

        try {
            $this->policy->assertTransition($message->status, $to, $source);
        } catch (\InvalidArgumentException $e) {
            return new StatusResult(
                $messageId,
                StatusResult::INVALID_TRANSITION,
                from: $message->status,
                to: $to,
                error: $e->getMessage(),
            );
        }

        if ($this->authorizer !== null && $source === StatusTransitionPolicy::SOURCE_APPLICATION) {
            $can = $to === MessageStatus::REJECTED
                ? $this->authorizer->canReject($ctx->actor, [$message])
                : $this->authorizer->canApprove($ctx->actor, [$message]);

            if (!$can) {
                return new StatusResult(
                    $messageId,
                    StatusResult::DENIED,
                    from: $message->status,
                    to: $to,
                    error: 'Not authorised',
                );
            }
        }

        if ($ctx->dryRun) {
            return new StatusResult(
                $messageId,
                StatusResult::CHANGED,
                from: $message->status,
                to: $to,
            );
        }

        $this->messageRepository->updateStatus($messageId, $to);
        $this->statusChangeRepository->record(
            $messageId,
            $message->batchId,
            $message->status,
            $to,
            $ctx->actor,
            $ctx->reason,
            $source,
        );

        return new StatusResult(
            $messageId,
            StatusResult::CHANGED,
            from: $message->status,
            to: $to,
        );
    }
}
