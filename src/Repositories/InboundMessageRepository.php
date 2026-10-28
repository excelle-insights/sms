<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\InboundMessage;
use PDO;

class InboundMessageRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'inbound_messages';
    }

    public function create(InboundMessage $message): InboundMessage
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, provider, provider_message_id, from_number, to_number, text, raw_payload, received_at, created_at)
             VALUES (:tenant_id, :provider, :provider_message_id, :from_number, :to_number, :text, :raw_payload, :received_at, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $message->tenantId,
            ':provider' => $message->provider,
            ':provider_message_id' => $message->providerMessageId,
            ':from_number' => $message->from,
            ':to_number' => $message->to,
            ':text' => $message->text,
            ':raw_payload' => json_encode(['from' => $message->from, 'to' => $message->to, 'text' => $message->text]),
            ':received_at' => $message->receivedAt?->format('Y-m-d H:i:s'),
        ]);

        return $message;
    }
}
