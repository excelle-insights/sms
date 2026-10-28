<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Repositories;

use ExcelleInsights\Sms\Entities\DeliveryReport;
use PDO;

class DeliveryReportRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'delivery_reports';
    }

    public function create(DeliveryReport $report, ?int $messageId = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (message_id, provider, raw_status, normalised_status, error_code, error_message, raw_payload, reported_at, created_at)
             VALUES (:message_id, :provider, :raw_status, :normalised_status, :error_code, :error_message, :raw_payload, :reported_at, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':message_id' => $messageId,
            ':provider' => $report->provider ?? '',
            ':raw_status' => $report->rawStatus,
            ':normalised_status' => $report->status->value,
            ':error_code' => $report->errorCode,
            ':error_message' => $report->errorMessage,
            ':raw_payload' => json_encode([
                'raw_status' => $report->rawStatus,
                'error_code' => $report->errorCode,
                'error_message' => $report->errorMessage,
            ]),
            ':reported_at' => $report->reportedAt?->format('Y-m-d H:i:s'),
        ]);
    }
}
