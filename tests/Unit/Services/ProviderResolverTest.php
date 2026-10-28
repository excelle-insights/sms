<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Services;

use ExcelleInsights\Sms\Config\SmsConfig;
use ExcelleInsights\Sms\Exceptions\NoDefaultProviderException;
use ExcelleInsights\Sms\Repositories\ProviderConfigRepository;
use ExcelleInsights\Sms\Services\ProviderResolver;
use ExcelleInsights\Sms\Support\Crypto;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProviderResolverTest extends TestCase
{
    private PDO $pdo;
    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE sms_provider_configs (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            provider_key TEXT,
            credentials TEXT,
            sender_id TEXT,
            enabled INTEGER DEFAULT 1,
            is_default INTEGER DEFAULT 0,
            UNIQUE(tenant_id, provider_key)
        )');

        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $repository = new ProviderConfigRepository($this->pdo, $crypto, 'sms_');
        $config = new SmsConfig([
            'encryption_key' => base64_encode(random_bytes(32)),
            'default_provider' => 'africastalking',
        ]);

        $this->resolver = new ProviderResolver($repository, $config);
    }

    public function testResolvesExplicitProvider(): void
    {
        $provider = $this->resolver->resolve('celcom', 1);

        self::assertSame('celcom', $provider);
    }

    public function testResolvesTenantDefaultBeforeEnvFallback(): void
    {
        $this->pdo->prepare(
            'INSERT INTO sms_provider_configs (tenant_id, provider_key, is_default) VALUES (?, ?, ?)',
        )->execute([7, 'celcom', 1]);

        $provider = $this->resolver->resolve(null, 7);

        self::assertSame('celcom', $provider);
    }

    public function testResolvesEnvFallbackWhenNoDatabaseDefault(): void
    {
        $provider = $this->resolver->resolve(null, 99);

        self::assertSame('africastalking', $provider);
    }

    public function testThrowsWhenNoDefaultExists(): void
    {
        $resolver = new ProviderResolver(
            new ProviderConfigRepository($this->pdo, new Crypto(base64_encode(random_bytes(32))), 'sms_'),
            new SmsConfig(['encryption_key' => base64_encode(random_bytes(32))]),
        );

        $this->expectException(NoDefaultProviderException::class);

        $resolver->resolve(null, 99);
    }
}
