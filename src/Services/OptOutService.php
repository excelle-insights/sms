<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Repositories\OptOutRepository;
use ExcelleInsights\Sms\Validation\PhoneNumberValidator;

class OptOutService
{
    public function __construct(
        private readonly OptOutRepository $optOutRepository,
        private readonly PhoneNumberValidator $phoneValidator,
    ) {
    }

    public function optOut(string $phoneNumber, ?int $tenantId = null, ?string $source = null): void
    {
        $this->optOutRepository->add($this->phoneValidator->normalise($phoneNumber), $tenantId, $source);
    }

    public function isOptedOut(string $phoneNumber, ?int $tenantId = null): bool
    {
        return $this->optOutRepository->exists($this->phoneValidator->normalise($phoneNumber), $tenantId);
    }
}
