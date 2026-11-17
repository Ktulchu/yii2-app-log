<?php
declare(strict_types=1);
namespace Example;
use Ktulchu\Yii2AppLog\AppLogBehavior;
use Ktulchu\Yii2AppLog\AppLogHelper;
use yii\db\ActiveRecord;
final class Note extends ActiveRecord
{
    public static function tableName(): string { return 'note'; }
    public function behaviors(): array
    {
        return ['audit' => [
            'class' => AppLogBehavior::class,
            'attributes' => [
                'id', 'title', 'is_deleted',
                'document' => ['type' => AppLogHelper::JSON_SINGLE_COMPLEX_ARRAY],
                'settings' => ['type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY],
            ],
            'actorProvider' => static fn ($model) => ['staff_id' => 7],
            'deletionMarkAttribute' => 'is_deleted',
            'deletionMarkValue' => true,
        ]];
    }
    public function getUpdatedModelClass(): string { return 'ExampleDocument'; }
    public function getUpdatedModelId(): int { return (int) $this->getAttribute('id'); }
}
