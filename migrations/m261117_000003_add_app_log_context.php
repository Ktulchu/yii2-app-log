<?php
declare(strict_types=1);

use yii\db\Migration;

final class m261117_000003_add_app_log_context extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%app_log}}', 'contact_id', 'bigint');
        $this->addColumn('{{%app_log}}', 'staff_id', 'bigint');
        $this->addColumn('{{%app_log}}', 'logical_entity_type', 'text');
        $this->addColumn('{{%app_log}}', 'logical_entity_id', 'jsonb');
        $this->addColumn('{{%app_log}}', 'is_soft_delete', 'boolean NOT NULL DEFAULT false');
        $this->createIndex('{{%app_log_logical_idx}}', '{{%app_log}}', ['logical_entity_type', 'logical_entity_id', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('{{%app_log_logical_idx}}', '{{%app_log}}');
        foreach (['is_soft_delete', 'logical_entity_id', 'logical_entity_type', 'staff_id', 'contact_id'] as $column) {
            $this->dropColumn('{{%app_log}}', $column);
        }
    }
}
