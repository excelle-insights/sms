<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Support\Crypto;
use PDO;

class ProviderConfigRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'provider_configs';
    }

    public function getDefaultProvider(?int $tenantId): ?string
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
        $stmt = $this->pdo->prepare(
            "SELECT provider_key FROM {$this->table} WHERE {$where} AND is_default = TRUE LIMIT 1",
        );

        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['provider_key'];
    }

    public function setDefaultProvider(string $providerKey, ?int $tenantId): void
    {
        $this->pdo->beginTransaction();

        try {
            [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
            $clear = $this->pdo->prepare(
                "UPDATE {$this->table} SET is_default = FALSE WHERE {$where}",
            );
            $clear->execute($params);

            $this->upsertDefault($providerKey, $tenantId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCredentials(string $providerKey, ?int $tenantId): ?array
    {
        $row = $this->findConfig($providerKey, $tenantId);

        if ($row === null || $row['credentials'] === null) {
            $row = $this->findConfig($providerKey, null);
        }

        if ($row === null || $row['credentials'] === null) {
            return null;
        }

        $decrypted = $this->crypto->decrypt((string) $row['credentials']);
        $decoded = json_decode($decrypted, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getSenderId(string $providerKey, ?int $tenantId): ?string
    {
        $row = $this->findConfig($providerKey, $tenantId);

        if ($row === null || $row['sender_id'] === null) {
            $row = $this->findConfig($providerKey, null);
        }

        return $row === null ? null : ($row['sender_id'] === null ? null : (string) $row['sender_id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findConfig(string $providerKey, ?int $tenantId): ?array
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} AND provider_key = :provider_key LIMIT 1",
        );

        $stmt->execute(array_merge($params, [':provider_key' => $providerKey]));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function saveCredentials(string $providerKey, ?int $tenantId, array $credentials, ?string $senderId = null): void
    {
        $json = json_encode($credentials);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode credentials to JSON');
        }

        $encrypted = $this->crypto->encrypt($json);

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (tenant_id, provider_key, credentials, sender_id, enabled, created_at, updated_at)
             VALUES (:tenant_id, :provider_key, :credentials, :sender_id, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), sender_id = VALUES(sender_id), updated_at = CURRENT_TIMESTAMP",
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider_key' => $providerKey,
            ':credentials' => $encrypted,
            ':sender_id' => $senderId,
        ]);
    }

    /**
     * Build a tenant-aware WHERE clause that works on MySQL and SQLite.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tenantWhere(string $column, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return ["{$column} IS NULL", []];
        }

        return ["{$column} = :tenant_id", [':tenant_id' => $tenantId]];
    }

    private function upsertDefault(string $providerKey, ?int $tenantId): void
    {
        $existing = $this->findConfig($providerKey, $tenantId);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET is_default = TRUE, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            );
            $stmt->execute([':id' => $existing['id']]);

            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (tenant_id, provider_key, is_default, created_at, updated_at)
         VALUES (:tenant_id, :provider_key, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider_key' => $providerKey,
        ]);
    }
}
