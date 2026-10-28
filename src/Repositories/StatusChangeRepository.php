<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\MessageStatus;
use PDO;

class StatusChangeRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'status_changes';
    }

    public function record(
        ?int $messageId,
        ?int $batchId,
        ?MessageStatus $from,
        MessageStatus $to,
        mixed $actor,
        ?string $reason,
        string $source,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (message_id, batch_id, from_status, to_status, actor, reason, source, created_at)
             VALUES (:message_id, :batch_id, :from_status, :to_status, :actor, :reason, :source, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':message_id' => $messageId,
            ':batch_id' => $batchId,
            ':from_status' => $from?->value,
            ':to_status' => $to->value,
            ':actor' => $actor === null ? null : (is_string($actor) || $actor instanceof \Stringable ? (string) $actor : json_encode($actor)),
            ':reason' => $reason,
            ':source' => $source,
        ]);
    }
}
