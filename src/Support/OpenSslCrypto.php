<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Support;

use ExcelleInsights\Sms\Exceptions\EncryptionException;

class OpenSslCrypto implements CryptoInterface
{
    private const VERSION_PREFIX = 'v1';
    private const GCM_CIPHER = 'aes-256-gcm';
    private const CBC_CIPHER = 'aes-256-cbc';
    private const GCM_IV_BYTES = 12;
    private const CBC_IV_BYTES = 16;
    private const GCM_TAG_BYTES = 16;
    private const HMAC_BYTES = 32;

    private readonly string $key;
    private readonly ?CryptoInterface $legacyCrypto;
    private readonly bool $gcmAvailable;

    public function __construct(string $key, ?CryptoInterface $legacyCrypto = null)
    {
        $raw = $this->decodeKey($key);

        if ($raw === null || strlen($raw) !== 32) {
            $raw = hash('sha256', $key, true);
        }

        $this->key = $raw;
        $this->legacyCrypto = $legacyCrypto ?? (function_exists('sodium_crypto_secretbox') ? new SodiumCrypto($key) : null);
        $this->gcmAvailable = in_array(self::GCM_CIPHER, openssl_get_cipher_methods(), true);
    }

    public function encrypt(string $plaintext): string
    {
        try {
            if ($this->gcmAvailable) {
                $iv = random_bytes(self::GCM_IV_BYTES);
                $tag = '';
                $ciphertext = openssl_encrypt(
                    $plaintext,
                    self::GCM_CIPHER,
                    $this->key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    '',
                    self::GCM_TAG_BYTES,
                );

                if ($ciphertext === false || strlen($tag) !== self::GCM_TAG_BYTES) {
                    throw new EncryptionException('Failed to encrypt value');
                }

                return self::VERSION_PREFIX . ':gcm:' . base64_encode($iv . $tag . $ciphertext);
            }

            $iv = random_bytes(self::CBC_IV_BYTES);
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CBC_CIPHER,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Failed to encrypt value');
            }

            $mac = hash_hmac('sha256', $iv . $ciphertext, $this->authKey(), true);

            return self::VERSION_PREFIX . ':cbc:' . base64_encode($iv . $mac . $ciphertext);
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncryptionException('Failed to encrypt value: ' . $e->getMessage(), 0, $e);
        }
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            if (str_starts_with($ciphertext, self::VERSION_PREFIX . ':')) {
                return $this->decryptCurrent($ciphertext);
            }

            if ($this->legacyCrypto !== null) {
                return $this->legacyCrypto->decrypt($ciphertext);
            }

            throw new EncryptionException('Unsupported ciphertext format');
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncryptionException('Failed to decrypt value: ' . $e->getMessage(), 0, $e);
        }
    }

    private function decryptCurrent(string $ciphertext): string
    {
        [$version, $mode, $encoded] = array_pad(explode(':', $ciphertext, 3), 3, null);

        if ($version !== self::VERSION_PREFIX || ! is_string($mode) || ! is_string($encoded)) {
            throw new EncryptionException('Invalid ciphertext format');
        }

        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            throw new EncryptionException('Invalid ciphertext encoding');
        }

        return match ($mode) {
            'gcm' => $this->decryptGcm($payload),
            'cbc' => $this->decryptCbc($payload),
            default => throw new EncryptionException('Unsupported ciphertext mode'),
        };
    }

    private function decryptGcm(string $payload): string
    {
        $minLength = self::GCM_IV_BYTES + self::GCM_TAG_BYTES + 1;
        if (strlen($payload) < $minLength) {
            throw new EncryptionException('Invalid ciphertext length');
        }

        $iv = substr($payload, 0, self::GCM_IV_BYTES);
        $tag = substr($payload, self::GCM_IV_BYTES, self::GCM_TAG_BYTES);
        $ciphertext = substr($payload, self::GCM_IV_BYTES + self::GCM_TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::GCM_CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new EncryptionException('Failed to decrypt value');
        }

        return $plaintext;
    }

    private function decryptCbc(string $payload): string
    {
        $minLength = self::CBC_IV_BYTES + self::HMAC_BYTES + 1;
        if (strlen($payload) < $minLength) {
            throw new EncryptionException('Invalid ciphertext length');
        }

        $iv = substr($payload, 0, self::CBC_IV_BYTES);
        $mac = substr($payload, self::CBC_IV_BYTES, self::HMAC_BYTES);
        $ciphertext = substr($payload, self::CBC_IV_BYTES + self::HMAC_BYTES);

        $expected = hash_hmac('sha256', $iv . $ciphertext, $this->authKey(), true);
        if (! hash_equals($expected, $mac)) {
            throw new EncryptionException('Failed to decrypt value');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CBC_CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($plaintext === false) {
            throw new EncryptionException('Failed to decrypt value');
        }

        return $plaintext;
    }

    private function authKey(): string
    {
        return hash_hmac('sha256', 'sms-encryption-auth', $this->key, true);
    }

    private function decodeKey(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $decoded = base64_decode($key, true);

        return $decoded === false ? null : $decoded;
    }
}
