<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use PDO;

class OptOutRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'opt_outs';
    }

    public function exists(string $phoneNumber, ?int $tenantId): bool
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$this->table} WHERE {$where} AND phone_number = :phone_number LIMIT 1",
        );

        $stmt->execute(array_merge($params, [':phone_number' => $phoneNumber]));

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function add(string $phoneNumber, ?int $tenantId, ?string $source = null): void
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);

        if ($this->exists($phoneNumber, $tenantId)) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET source = :source WHERE {$where} AND phone_number = :phone_number",
            );
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table} (tenant_id, phone_number, source, created_at)
                 VALUES (:tenant_id, :phone_number, :source, CURRENT_TIMESTAMP)",
            );
        }

        $stmt->execute(array_merge($params, [
            ':phone_number' => $phoneNumber,
            ':source' => $source,
        ]));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tenantWhere(string $column, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return ["{$column} IS NULL", []];
        }

        return ["{$column} = :tenant_id", [':tenant_id' => $tenantId]];
    }
}
