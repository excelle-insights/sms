<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\SmsTemplate;
use PDO;

class TemplateRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'templates';
    }

    public function create(SmsTemplate $template): SmsTemplate
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, key, name, category, sender_id, status, current_version, created_at, updated_at)
             VALUES (:tenant_id, :key, :name, :category, :sender_id, :status, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $template->tenantId,
            ':key' => $template->key,
            ':name' => $template->name,
            ':category' => $template->category,
            ':sender_id' => $template->senderId,
            ':status' => $template->status,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function update(SmsTemplate $template): SmsTemplate
    {
        if ($template->id === null) {
            throw new \InvalidArgumentException('Cannot update a template without an id');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET name = :name, category = :category, sender_id = :sender_id,
                 current_version = current_version + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );

        $stmt->execute([
            ':name' => $template->name,
            ':category' => $template->category,
            ':sender_id' => $template->senderId,
            ':id' => $template->id,
        ]);

        return $this->findById($template->id);
    }

    public function findById(int $id): SmsTemplate
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Template {$id} not found");
        }

        return $this->mapRow($row);
    }

    public function findByKey(string $key, ?int $tenantId): ?SmsTemplate
    {
        // Tenant-specific template wins over global.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE key = :key AND (tenant_id = :tenant_id OR tenant_id IS NULL)
             ORDER BY tenant_id DESC
             LIMIT 1",
        );

        $stmt->execute([':key' => $key, ':tenant_id' => $tenantId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * @return array<int, SmsTemplate>
     */
    public function list(?int $tenantId, ?string $status = null): array
    {
        $where = 'WHERE tenant_id <=> :tenant_id OR tenant_id IS NULL';
        $params = [':tenant_id' => $tenantId];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} {$where} ORDER BY name ASC");
        $stmt->execute($params);

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function archive(int $id): void
    {
        $this->setStatus($id, SmsTemplate::STATUS_ARCHIVED);
    }

    public function activate(int $id): void
    {
        $this->setStatus($id, SmsTemplate::STATUS_ACTIVE);
    }

    private function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );

        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): SmsTemplate
    {
        return new SmsTemplate(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            key: (string) $row['key'],
            name: (string) $row['name'],
            body: '',
            category: (string) $row['category'],
            status: (string) $row['status'],
            version: (int) $row['current_version'],
            senderId: $row['sender_id'] === null ? null : (string) $row['sender_id'],
        );
    }
}
