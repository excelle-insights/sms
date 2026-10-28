<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Contracts;

use ExcelleInsights\Sms\Entities\Message;

interface ApprovalAuthorizerInterface
{
    /**
     * @param array<int, Message> $messages
     */
    public function canApprove(object $actor, array $messages): bool;

    /**
     * @param array<int, Message> $messages
     */
    public function canReject(object $actor, array $messages): bool;
}
