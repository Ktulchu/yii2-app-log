<?php
declare(strict_types=1);
use yii\db\Migration;
final class m261117_000002_create_note extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('note', [
            'id' => $this->bigPrimaryKey(),
            'title' => $this->text(),
            'document' => 'json',
            'settings' => 'jsonb',
            'is_deleted' => $this->boolean()->notNull()->defaultValue(false),
        ]);
    }
    public function safeDown(): void { $this->dropTable('note'); }
}
