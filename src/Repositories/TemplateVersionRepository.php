<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use PDO;

class TemplateVersionRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'template_versions';
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function create(int $templateId, int $version, string $body, array $variables, ?string $author): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (template_id, version, body, variables, author, created_at)
             VALUES (:template_id, :version, :body, :variables, :author, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':template_id' => $templateId,
            ':version' => $version,
            ':body' => $body,
            ':variables' => json_encode($variables),
            ':author' => $author,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $templateId, int $version): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE template_id = :template_id AND version = :version LIMIT 1",
        );

        $stmt->execute([':template_id' => $templateId, ':version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['variables'] = $row['variables'] === null ? [] : json_decode((string) $row['variables'], true);

        return $row;
    }
}
