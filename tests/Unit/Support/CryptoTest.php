<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Support;

use ExcelleInsights\Sms\Support\OpenSslCrypto;
use ExcelleInsights\Sms\Support\SodiumCrypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testEncryptsAndDecrypts(): void
    {
        $key = base64_encode(random_bytes(32));
        $crypto = new OpenSslCrypto($key);
        $plaintext = 'api-key:secret-value';

        $cipher = $crypto->encrypt($plaintext);

        self::assertNotSame($plaintext, $cipher);
        self::assertSame($plaintext, $crypto->decrypt($cipher));
    }

    public function testDecryptsLegacySodiumCiphertext(): void
    {
        $key = base64_encode(random_bytes(32));
        $legacy = new SodiumCrypto($key);
        $crypto = new OpenSslCrypto($key);

        $cipher = $legacy->encrypt('hello');

        self::assertSame('hello', $crypto->decrypt($cipher));
    }

    public function testFallsBackToCbcWhenGcmUnavailable(): void
    {
        $crypto = new OpenSslCrypto(base64_encode(random_bytes(32)));

        self::assertNotSame('', $crypto->encrypt('fallback-check'));
        self::assertSame('fallback-check', $crypto->decrypt($crypto->encrypt('fallback-check')));
    }
}
