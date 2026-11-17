<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog;

use yii\db\Expression;

/** An explicit SQL value; never pass already-encoded JSON as a plain Yii attribute string. */
final class RawJson
{
    public static function value(string $json, bool $binary = false): Expression
    {
        // PostgreSQL validates the document without decoding its numbers in PHP.
        static $sequence = 0;
        $parameter = ':app_log_json_' . ++$sequence;
        return new Expression('CAST(' . $parameter . ' AS ' . ($binary ? 'jsonb' : 'json') . ')', [$parameter => $json]);
    }

    public static function sqlNull(): Expression
    {
        return new Expression('NULL');
    }
}
