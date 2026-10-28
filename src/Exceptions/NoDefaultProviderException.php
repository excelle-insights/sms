<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Exceptions;

class NoDefaultProviderException extends SmsException
{
    public function __construct(string $message = 'No default SMS provider is configured')
    {
        parent::__construct($message);
    }
}
