<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

final class NullCrypto implements CryptoInterface
{
    public function encrypt(string $plaintext): string
    {
        return $plaintext;
    }

    public function decrypt(string $ciphertext): string
    {
        return $ciphertext;
    }
}
