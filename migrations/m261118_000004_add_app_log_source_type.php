<?php
declare(strict_types=1);

use yii\db\Migration;

final class m261118_000004_add_app_log_source_type extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%app_log}}', 'source_type', 'varchar(255) NULL');
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%app_log}}', 'source_type');
    }
}
