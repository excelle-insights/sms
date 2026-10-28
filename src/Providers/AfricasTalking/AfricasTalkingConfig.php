<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\AfricasTalking;

class AfricasTalkingConfig
{
    public function __construct(
        public readonly string $username,
        public readonly string $apiKey,
        public readonly ?string $senderId = null,
        public readonly bool $sandbox = false,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://api.sandbox.africastalking.com/version1'
            : 'https://api.africastalking.com/version1';
    }
}
