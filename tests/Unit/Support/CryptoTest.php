<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests\Unit\Support;

use ExcelleInsights\Sms\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testEncryptsAndDecrypts(): void
    {
        $key = base64_encode(random_bytes(32));
        $crypto = new Crypto($key);
        $plaintext = 'api-key:secret-value';

        $cipher = $crypto->encrypt($plaintext);

        self::assertNotSame($plaintext, $cipher);
        self::assertSame($plaintext, $crypto->decrypt($cipher));
    }

    public function testDerivesKeyFromArbitraryString(): void
    {
        $crypto = new Crypto('short-password');

        $cipher = $crypto->encrypt('hello');

        self::assertSame('hello', $crypto->decrypt($cipher));
    }
}
