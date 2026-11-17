<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog;

use yii\helpers\Html;

final class AppLogHelper
{
    public const TYPE_SINGLE_RECORD = 10;
    public const TYPE_TRANSACTION = 20;
    public const TYPES = [self::TYPE_SINGLE_RECORD => 'Строка таблицы'];
    public const MAX_WORD_COUNT_FOR_OUTPUT = 5;
    public const ACTION_CREATE = 10;
    public const ACTION_UPDATE = 20;
    public const ACTION_DELETE = 30;
    public const ACTIONS = [
        self::ACTION_CREATE => [0 => 'Добавлено', self::TYPE_SINGLE_RECORD => 'Создана запись'],
        self::ACTION_UPDATE => [0 => 'Исправлено', self::TYPE_SINGLE_RECORD => 'Запись исправлена'],
        self::ACTION_DELETE => [0 => 'Удалено', self::TYPE_SINGLE_RECORD => 'Запись удалена'],
    ];
    public const ACTIONS_LIST = [
        self::ACTION_CREATE => 'Создана запись',
        self::ACTION_UPDATE => 'Запись исправлена',
        self::ACTION_DELETE => 'Запись удалена',
    ];
    public const JSON_SINGLE_SIMPLE_ARRAY = 'JSON_SINGLE_SIMPLE_ARRAY';
    public const JSON_SINGLE_ASSOC_ARRAY = 'JSON_SINGLE_ASSOC_ARRAY';
    public const JSON_ASSOC_ARRAYS_COLLECTION = 'JSON_ASSOC_ARRAYS_COLLECTION';
    public const JSON_SINGLE_COMPLEX_ARRAY = 'JSON_SINGLE_COMPLEX_ARRAY';
    public const JSON_TYPES = [
        self::JSON_SINGLE_SIMPLE_ARRAY, self::JSON_SINGLE_ASSOC_ARRAY,
        self::JSON_ASSOC_ARRAYS_COLLECTION, self::JSON_SINGLE_COMPLEX_ARRAY,
    ];

    /** @return array<int, string> */
    public static function getActionListItems(int $typeId = 0): array
    {
        $items = [];
        foreach (self::ACTIONS as $id => $labels) {
            if (isset($labels[$typeId])) { $items[$id] = $labels[$typeId]; }
        }
        return $items;
    }

    public static function getActionName(int $id, int $typeId = 0, string $defaultValue = '#N/A'): string
    {
        return self::ACTIONS[$id][$typeId] ?? $defaultValue;
    }

    /** @param array<array-key, mixed> $attributes */
    public static function isJson(array $attributes, string $attrKey): bool
    {
        $configuration = $attributes[$attrKey] ?? null;
        return is_array($configuration) && in_array($configuration['type'] ?? null, self::JSON_TYPES, true);
    }

    public static function getShortClassName(string $modelClassName): string
    {
        return basename(str_replace('\\', '/', $modelClassName));
    }

    /** @deprecated Compatibility only: the source always returned true. */
    public static function isUnderConstruction(mixed $attribute): bool
    {
        return true;
    }

    /** Empty only means all; except always wins.
     * @param array<string, mixed> $configuration */
    public static function isActive(array $configuration, string $key): bool
    {
        return (empty($configuration['only']) || in_array($key, $configuration['only'], true))
            && !in_array($key, $configuration['except'] ?? [], true);
    }

    /** Render legacy presentation rows without application-specific formatters.
     * @param array<array-key, array<string, mixed>> $attributes
     */
    public static function getSingleRecordLogAsHtml(array $attributes, string $modelClass = ''): string
    {
        $rows = '';
        foreach ($attributes as $entry) {
            if (array_key_exists('label', $entry)) {
                $rows .= self::renderAttributeAsTableRow($entry, $modelClass);
            } else {
                foreach ($entry as $row) {
                    if (is_array($row)) {
                        $rows .= self::renderAttributeAsTableRow($row, $modelClass);
                    }
                }
            }
        }
        return Html::tag('table', Html::tag('tbody', $rows), ['class' => 'table table-sm inner_log_table']);
    }

    /** @param array<string, mixed> $attribute */
    public static function renderAttributeAsTableRow(array $attribute, string $modelClass = ''): string
    {
        $text = static function (mixed $value): string {
            return is_array($value) || is_object($value)
                ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        };
        return Html::tag('tr',
            Html::tag('td', Html::encode($text($attribute['label'] ?? '')))
            . Html::tag('td', Html::tag('s', Html::encode($text($attribute['oldRaw'] ?? $attribute['oldVal'] ?? ''))))
            . Html::tag('td', Html::encode($text($attribute['newRaw'] ?? $attribute['newVal'] ?? '')))
        );
    }
}
