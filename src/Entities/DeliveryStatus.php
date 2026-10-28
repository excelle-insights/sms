<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Entities;

enum DeliveryStatus: string
{
    case NONE = 'none';
    case WITH_CARRIER = 'with_carrier';
    case DELIVERED = 'delivered';
    case UNDELIVERED = 'undelivered';
    case EXPIRED = 'expired';
    case UNKNOWN = 'unknown';
}
