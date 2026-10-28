<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

use ExcelleInsights\Sms\Exceptions\EncryptionException;

class Crypto
{
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private readonly string $key;

    public function __construct(string $key)
    {
        $raw = $this->decodeKey($key);

        if ($raw === null || strlen($raw) !== self::KEY_BYTES) {
            $raw = hash('sha256', $key, true);
        }

        $this->key = $raw;
    }

    /**
     * @return string|null
     */
    private function decodeKey(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        try {
            return sodium_base642bin($key, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return null;
        }
    }

    /**
     * Encrypt a string and return a base64-encoded ciphertext.
     *
     * @throws EncryptionException
     */
    public function encrypt(string $plaintext): string
    {
        try {
            $nonce = random_bytes(self::NONCE_BYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

            return sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\Throwable $e) {
            throw new EncryptionException('Failed to encrypt value: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrypt a base64-encoded ciphertext.
     *
     * @throws EncryptionException
     */
    public function decrypt(string $ciphertext): string
    {
        try {
            $combined = sodium_base642bin($ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);

            if (strlen($combined) < self::NONCE_BYTES + 1) {
                throw new EncryptionException('Invalid ciphertext length');
            }

            $nonce = substr($combined, 0, self::NONCE_BYTES);
            $cipher = substr($combined, self::NONCE_BYTES);
            $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

            if ($plaintext === false) {
                throw new EncryptionException('Failed to decrypt value');
            }

            return $plaintext;
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncryptionException('Failed to decrypt value: ' . $e->getMessage(), 0, $e);
        }
    }
}
