<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSmsTables extends AbstractMigration
{
    private string $prefix;

    public function up(): void
    {
        $this->prefix = $this->getAdapter()->getOption('table_prefix') ?? 'sms_';

        $this->createProviderConfigsTable();
        $this->createMessagesTable();
        $this->createBatchesTable();
        $this->createDeliveryReportsTable();
        $this->createStatusChangesTable();
        $this->createTenantSettingsTable();
        $this->createTemplatesTable();
        $this->createTemplateVersionsTable();
        $this->createInboundMessagesTable();
        $this->createOptOutsTable();
        $this->createWebhookLogsTable();
        $this->createLogsTable();
        $this->createHttpRequestLogsTable();
    }

    public function down(): void
    {
        $this->prefix = $this->getAdapter()->getOption('table_prefix') ?? 'sms_';

        $this->table('http_request_logs')->drop()->save();
        $this->table('logs')->drop()->save();
        $this->table('webhook_logs')->drop()->save();
        $this->table('opt_outs')->drop()->save();
        $this->table('inbound_messages')->drop()->save();
        $this->table('template_versions')->drop()->save();
        $this->table('templates')->drop()->save();
        $this->table('tenant_settings')->drop()->save();
        $this->table('status_changes')->drop()->save();
        $this->table('delivery_reports')->drop()->save();
        $this->table('batches')->drop()->save();
        $this->table('messages')->drop()->save();
        $this->table('provider_configs')->drop()->save();
    }

    private function createProviderConfigsTable(): void
    {
        $table = $this->table('provider_configs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('credentials', 'text', ['null' => true])
            ->addColumn('sender_id', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('is_default', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'provider_key'], ['unique' => true])
            ->addIndex(['tenant_id'])
            ->addIndex(['provider_key'])
            ->create();

        // Enforce exactly one default provider per tenant using a generated column.
        $this->execute(
            "ALTER TABLE {$this->prefix}provider_configs "
            . 'ADD COLUMN default_tenant_id INT AS (CASE WHEN is_default = TRUE THEN tenant_id ELSE NULL END) STORED, '
            . 'ADD UNIQUE INDEX idx_default_provider (default_tenant_id)',
        );
    }

    private function createMessagesTable(): void
    {
        $table = $this->table('messages', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('batch_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('provider_message_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('to', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('encoding', 'string', ['limit' => 16, 'null' => false, 'default' => 'gsm7'])
            ->addColumn('segments', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('status', 'string', ['limit' => 32, 'null' => false, 'default' => 'queued'])
            ->addColumn('delivery_status', 'string', ['limit' => 32, 'null' => false, 'default' => 'none'])
            ->addColumn('approved_by', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('approved_at', 'datetime', ['null' => true])
            ->addColumn('rejected_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('template_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('template_version', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('template_variables', 'json', ['null' => true])
            ->addColumn('scheduled_at', 'datetime', ['null' => true])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('idempotency_key', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('error', 'text', ['null' => true])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'status'])
            ->addIndex(['tenant_id', 'delivery_status'])
            ->addIndex(['batch_id'])
            ->addIndex(['idempotency_key'])
            ->addIndex(['scheduled_at'])
            ->addIndex(['provider_message_id'])
            ->create();
    }

    private function createBatchesTable(): void
    {
        $table = $this->table('batches', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('status', 'string', ['limit' => 32, 'null' => false, 'default' => 'queued'])
            ->addColumn('total', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('queued', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('sent', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('delivered', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('failed', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'status'])
            ->create();
    }

    private function createDeliveryReportsTable(): void
    {
        $table = $this->table('delivery_reports', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('message_id', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('raw_status', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('normalised_status', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('error_code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('raw_payload', 'json', ['null' => true])
            ->addColumn('reported_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['message_id'])
            ->create();
    }

    private function createStatusChangesTable(): void
    {
        $table = $this->table('status_changes', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('message_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('batch_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('from_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('to_status', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('actor', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 32, 'null' => false, 'default' => 'application'])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['message_id'])
            ->addIndex(['batch_id'])
            ->create();
    }

    private function createTenantSettingsTable(): void
    {
        $table = $this->table('tenant_settings', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('default_timezone', 'string', ['limit' => 64, 'null' => false, 'default' => 'Africa/Nairobi'])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'], ['unique' => true])
            ->create();
    }

    private function createTemplatesTable(): void
    {
        $table = $this->table('templates', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('key', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('category', 'string', ['limit' => 32, 'null' => false, 'default' => 'marketing'])
            ->addColumn('sender_id', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 32, 'null' => false, 'default' => 'active'])
            ->addColumn('current_version', 'integer', ['null' => false, 'default' => 1, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'key'], ['unique' => true])
            ->create();
    }

    private function createTemplateVersionsTable(): void
    {
        $table = $this->table('template_versions', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('template_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('version', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('variables', 'json', ['null' => true])
            ->addColumn('author', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['template_id', 'version'], ['unique' => true])
            ->create();
    }

    private function createInboundMessagesTable(): void
    {
        $table = $this->table('inbound_messages', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('provider_message_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('from_number', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('to_number', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('text', 'text', ['null' => false])
            ->addColumn('raw_payload', 'json', ['null' => true])
            ->addColumn('received_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'from_number'])
            ->addIndex(['provider', 'created_at'])
            ->create();
    }

    private function createOptOutsTable(): void
    {
        $table = $this->table('opt_outs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('phone_number', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'phone_number'], ['unique' => true])
            ->create();
    }

    private function createWebhookLogsTable(): void
    {
        $table = $this->table('webhook_logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('direction', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('headers', 'json', ['null' => true])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['provider', 'created_at'])
            ->create();
    }

    private function createLogsTable(): void
    {
        $table = $this->table('logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('level', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    private function createHttpRequestLogsTable(): void
    {
        $table = $this->table('http_request_logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('method', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('url', 'text', ['null' => false])
            ->addColumn('request_headers', 'json', ['null' => true])
            ->addColumn('request_body', 'text', ['null' => true])
            ->addColumn('response_status', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('response_body', 'text', ['null' => true])
            ->addColumn('response_headers', 'json', ['null' => true])
            ->addColumn('duration_ms', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['provider', 'created_at'])
            ->create();
    }
}
