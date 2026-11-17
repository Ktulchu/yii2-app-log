<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog;

use yii\db\Connection;
use yii\db\Query;

final class History
{
    /** Raw JSON strings intentionally avoid lossy automatic hydration.
     * @param array<string, mixed> $primaryKey
     * @return list<array<string, mixed>>
     */
    public static function read(Connection $db, string $entityType, array $primaryKey, int $limit = 100, int $afterId = 0): array
    {
        if ($limit < 1 || $limit > 1000 || $afterId < 0) {
            throw new \InvalidArgumentException('Use a limit of 1..1000 and a nonnegative cursor.');
        }
        return array_values((new Query())->from('{{%app_log}}')
            ->where('entity_type = :type AND entity_id = CAST(:key AS jsonb) AND id > :cursor', [
                ':type' => $entityType,
                ':key' => json_encode($primaryKey, JSON_THROW_ON_ERROR),
                ':cursor' => $afterId,
            ])->orderBy(['id' => SORT_ASC])->limit($limit)->all($db));
    }
}
