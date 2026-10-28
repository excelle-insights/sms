<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Providers;

use ExcelleInsights\Sms\Contracts\SmsProviderInterface;
use ExcelleInsights\Sms\Exceptions\ProviderNotFoundException;

class ProviderRegistry
{
    /**
     * @var array<string, SmsProviderInterface>
     */
    private array $providers = [];

    public function register(SmsProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    public function get(string $key): SmsProviderInterface
    {
        if (!isset($this->providers[$key])) {
            throw new ProviderNotFoundException($key);
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<string, SmsProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
