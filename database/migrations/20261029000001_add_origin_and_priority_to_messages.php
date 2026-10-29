<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOriginAndPriorityToMessages extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('messages');

        if (!$table->hasColumn('origin')) {
            $table->addColumn('origin', 'string', ['limit' => 64, 'null' => false, 'default' => 'campaign']);
        }

        if (!$table->hasColumn('priority')) {
            $table->addColumn('priority', 'integer', ['null' => false, 'default' => 5]);
        }

        $table->addIndex(['status', 'priority']);
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('messages');
        $table->removeColumn('origin');
        $table->removeColumn('priority');
        $table->update();
    }
}
