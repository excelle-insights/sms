<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Services;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Exceptions\NoDefaultProviderException;
use ExcelleInsights\Sms\Repositories\ProviderConfigRepository;

class ProviderResolver
{
    public function __construct(
        private readonly ProviderConfigRepository $configRepository,
        private readonly SmsConfig $config,
    ) {
    }

    /**
     * Resolve the provider to use for a send.
     *
     * Resolution order:
     * 1. Explicitly requested provider.
     * 2. Tenant default provider.
     * 3. Global default provider.
     * 4. `.env` bootstrap fallback (`SMS_DEFAULT_PROVIDER`).
     *
     * @throws NoDefaultProviderException
     */
    public function resolve(?string $explicitProvider, ?int $tenantId): string
    {
        if ($explicitProvider !== null && $explicitProvider !== '') {
            return $explicitProvider;
        }

        $tenantDefault = $this->configRepository->getDefaultProvider($tenantId);
        if ($tenantDefault !== null) {
            return $tenantDefault;
        }

        $globalDefault = $this->configRepository->getDefaultProvider(null);
        if ($globalDefault !== null) {
            return $globalDefault;
        }

        $envDefault = $this->config->defaultProvider();
        if ($envDefault !== null) {
            return $envDefault;
        }

        throw new NoDefaultProviderException();
    }
}
