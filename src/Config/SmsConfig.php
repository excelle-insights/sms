<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Config;

class SmsConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function tablePrefix(): string
    {
        return (string) ($this->config['table_prefix'] ?? 'sms_');
    }

    public function encryptionKey(): string
    {
        $key = $this->config['encryption_key'] ?? '';

        if ($key === '') {
            throw new \RuntimeException('SMS encryption key is not configured');
        }

        return $key;
    }

    public function defaultCountry(): string
    {
        return (string) ($this->config['default_country'] ?? 'KE');
    }

    public function defaultProvider(): ?string
    {
        $provider = $this->config['default_provider'] ?? null;

        return $provider === null ? null : (string) $provider;
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->config['webhook_secret'] ?? null;

        return $secret === null ? null : (string) $secret;
    }

    public function maxSegments(): int
    {
        return (int) ($this->config['max_segments'] ?? 6);
    }

    public function retryAttempts(): int
    {
        return (int) ($this->config['retry_attempts'] ?? 5);
    }

    /**
     * @return array<int, int>
     */
    public function retryDelays(): array
    {
        return $this->config['retry_delays'] ?? [1, 2, 4, 8, 16];
    }

    public function idempotencyKeyTtlHours(): int
    {
        return (int) ($this->config['idempotency_key_ttl_hours'] ?? 24);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerConfig(string $provider): array
    {
        return $this->config['providers'][$provider] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
