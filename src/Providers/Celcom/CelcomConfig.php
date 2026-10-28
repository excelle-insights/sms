<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers\Celcom;

class CelcomConfig
{
    private const BASE_URL = 'https://isms.celcomafrica.com/api/services';

    public function __construct(
        public readonly string $partnerId,
        public readonly string $apiKey,
        public readonly ?string $senderId = null,
        public readonly string $baseUrl = self::BASE_URL,
    ) {
    }
}
