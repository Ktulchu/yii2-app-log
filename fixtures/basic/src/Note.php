<?php
declare(strict_types=1);
namespace Example;
use Ktulchu\Yii2AppLog\AppLogBehavior;
use yii\db\ActiveRecord;
final class Note extends ActiveRecord
{
    public static function tableName(): string { return 'note'; }
    public function behaviors(): array
    {
        return ['audit' => ['class' => AppLogBehavior::class]];
    }
}
