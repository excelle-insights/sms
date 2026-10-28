<?php

declare(strict_types=1);

namespace ExcelleInsights\Sms\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_provider_configs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider_key TEXT NOT NULL,
    credentials TEXT,
    sender_id TEXT,
    enabled INTEGER DEFAULT 1,
    is_default INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, provider_key)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    batch_id INTEGER,
    provider TEXT NOT NULL,
    provider_message_id TEXT,
    `to` TEXT NOT NULL,
    body TEXT NOT NULL,
    encoding TEXT DEFAULT 'gsm7',
    segments INTEGER DEFAULT 1,
    status TEXT DEFAULT 'queued',
    delivery_status TEXT DEFAULT 'none',
    approved_by TEXT,
    approved_at TEXT,
    rejected_reason TEXT,
    template_id INTEGER,
    template_version INTEGER,
    template_variables TEXT,
    scheduled_at TEXT,
    attempts INTEGER DEFAULT 0,
    idempotency_key TEXT,
    error TEXT,
    metadata TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    status TEXT DEFAULT 'queued',
    total INTEGER DEFAULT 0,
    queued INTEGER DEFAULT 0,
    sent INTEGER DEFAULT 0,
    delivered INTEGER DEFAULT 0,
    failed INTEGER DEFAULT 0,
    metadata TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    key TEXT NOT NULL,
    name TEXT NOT NULL,
    category TEXT DEFAULT 'marketing',
    sender_id TEXT,
    status TEXT DEFAULT 'active',
    current_version INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, key)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_template_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    version INTEGER NOT NULL,
    body TEXT NOT NULL,
    variables TEXT,
    author TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(template_id, version)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_opt_outs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    phone_number TEXT NOT NULL,
    source TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, phone_number)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_status_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER,
    batch_id INTEGER,
    from_status TEXT,
    to_status TEXT NOT NULL,
    actor TEXT,
    reason TEXT,
    source TEXT DEFAULT 'application',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_delivery_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER,
    provider TEXT NOT NULL,
    raw_status TEXT,
    normalised_status TEXT NOT NULL,
    error_code TEXT,
    error_message TEXT,
    raw_payload TEXT,
    reported_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_inbound_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider TEXT NOT NULL,
    provider_message_id TEXT,
    from_number TEXT NOT NULL,
    to_number TEXT NOT NULL,
    text TEXT NOT NULL,
    raw_payload TEXT,
    received_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE sms_webhook_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider TEXT NOT NULL,
    direction TEXT NOT NULL,
    headers TEXT,
    payload TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }
}
