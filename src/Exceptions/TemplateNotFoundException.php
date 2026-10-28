<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Exceptions;

class TemplateNotFoundException extends SmsException
{
    public function __construct(string $key)
    {
        parent::__construct("Template '{$key}' not found or archived");
    }
}
