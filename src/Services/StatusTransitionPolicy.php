<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Entities\MessageStatus;
use InvalidArgumentException;

class StatusTransitionPolicy
{
    public const SOURCE_APPLICATION = 'application';
    public const SOURCE_WORKER = 'worker';
    public const SOURCE_SYSTEM = 'system';

    /**
     * @return array<int, MessageStatus>
     */
    public function allowedTransitions(MessageStatus $from, string $source = self::SOURCE_APPLICATION): array
    {
        return match ($source) {
            self::SOURCE_WORKER => $this->workerTransitions($from),
            default => $this->applicationTransitions($from),
        };
    }

    public function canTransition(MessageStatus $from, MessageStatus $to, string $source = self::SOURCE_APPLICATION): bool
    {
        return in_array($to, $this->allowedTransitions($from, $source), true);
    }

    public function assertTransition(MessageStatus $from, MessageStatus $to, string $source = self::SOURCE_APPLICATION): void
    {
        if (!$this->canTransition($from, $to, $source)) {
            throw new InvalidArgumentException(
                sprintf('Cannot transition from %s to %s', $from->value, $to->value),
            );
        }
    }

    /**
     * @return array<int, MessageStatus>
     */
    private function applicationTransitions(MessageStatus $from): array
    {
        return match ($from) {
            MessageStatus::PENDING => [MessageStatus::QUEUED, MessageStatus::SCHEDULED, MessageStatus::REJECTED, MessageStatus::CANCELLED],
            MessageStatus::SCHEDULED => [MessageStatus::QUEUED, MessageStatus::SCHEDULED, MessageStatus::CANCELLED],
            MessageStatus::QUEUED => [MessageStatus::SCHEDULED, MessageStatus::CANCELLED],
            MessageStatus::FAILED => [MessageStatus::QUEUED, MessageStatus::CANCELLED],
            MessageStatus::REJECTED => [MessageStatus::PENDING],
            default => [],
        };
    }

    /**
     * @return array<int, MessageStatus>
     */
    private function workerTransitions(MessageStatus $from): array
    {
        return match ($from) {
            MessageStatus::SCHEDULED => [MessageStatus::QUEUED],
            MessageStatus::QUEUED => [MessageStatus::PROCESSING],
            MessageStatus::PROCESSING => [MessageStatus::SENT, MessageStatus::FAILED, MessageStatus::QUEUED],
            default => [],
        };
    }
}
