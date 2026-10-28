<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Exceptions;

class ProviderNotFoundException extends SmsException
{
    public function __construct(string $provider)
    {
        parent::__construct("SMS provider '{$provider}' is not registered");
    }
}
