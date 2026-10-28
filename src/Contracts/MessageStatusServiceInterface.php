<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Contracts;

use ExcelleInsights\Sms\Entities\MessageStatus;
use ExcelleInsights\Sms\Entities\StatusContext;
use ExcelleInsights\Sms\Entities\StatusResult;

interface MessageStatusServiceInterface
{
    /**
     * Apply any allowed transition to a single message.
     */
    public function change(int $messageId, MessageStatus $to, StatusContext $ctx): StatusResult;

    /**
     * Apply any allowed transition to many messages.
     *
     * @param array<int, int> $messageIds
     */
    public function changeMany(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult;

    /**
     * Apply any allowed transition to every message in a batch.
     */
    public function changeBatch(int $batchId, MessageStatus $to, StatusContext $ctx): BulkStatusResult;

    /**
     * List the statuses a message may be moved to by the application.
     *
     * @return array<int, MessageStatus>
     */
    public function allowedTransitions(int $messageId): array;

    public function approve(int $messageId, StatusContext $ctx): StatusResult;

    public function reject(int $messageId, StatusContext $ctx): StatusResult;

    public function cancel(int $messageId, StatusContext $ctx): StatusResult;

    public function reschedule(int $messageId, StatusContext $ctx): StatusResult;

    public function retry(int $messageId, StatusContext $ctx): StatusResult;

    public function resubmit(int $messageId, StatusContext $ctx): StatusResult;
}
