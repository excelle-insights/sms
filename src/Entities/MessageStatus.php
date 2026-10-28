<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

enum MessageStatus: string
{
    case PENDING = 'pending';
    case SCHEDULED = 'scheduled';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case FAILED = 'failed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
