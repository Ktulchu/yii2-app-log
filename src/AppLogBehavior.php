<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog;

use yii\base\Behavior;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\db\Transaction;

/**
 * Attach only to selected models. Requires an application-owned transaction.
 * @phpstan-type Snapshot array<string, array{type: string, raw: ?string, json: string}>
 * @phpstan-import-type Options from JsonChanges
 */
final class AppLogBehavior extends Behavior
{
    /** Column names, scalar callbacks, or label/value/preFilter options. Empty means all columns.
     * @var array<int|string, string|callable|Options>
     */
    public array $attributes = [];
    /** @var list<string> */
    public array $except = [];
    public ?int $actorContactId = null;
    public ?int $actorStaffId = null;
    /** Origin of the write, e.g. console:orders/sync or queue:PaymentJob. */
    public ?string $sourceType = null;
    /** @var callable(ActiveRecord): array{contact_id?: ?int, staff_id?: ?int}|null */
    public $actorProvider = null;
    public ?string $deletionMarkAttribute = null;
    /** Null preserves the source's any-change classification. Set a scalar for targeted deletion. */
    public mixed $deletionMarkValue = null;
    /** @var Snapshot */
    private array $before = [];
    /** @var list<string> */
    private array $fields = [];
    /** @var array<string, Options> */
    private array $fieldOptions = [];
    private ?Connection $connection = null;
    private ?Transaction $transaction = null;
    private ?\PDO $pdo = null;
    private int $level = 0;
    /** @var array<string, mixed> */
    private array $key = [];
    private ?string $operation = null;
    private ?string $resolvedSourceType = null;
    /** @var \WeakMap<Connection, bool>|null */
    private static ?\WeakMap $executing = null;

    /** @return array<string, string> */
    public function events(): array
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeWrite',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeWrite',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeWrite',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterWrite',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterWrite',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterWrite',
        ];
    }

    private function record(): ActiveRecord
    {
        if (!$this->owner instanceof ActiveRecord) {
            throw new \LogicException('AppLogBehavior requires yii\\db\\ActiveRecord.');
        }
        return $this->owner;
    }

    public function beforeWrite(Event $event): void
    {
        $model = $this->record();
        // Reject nested model writes from lifecycle handlers, including writes on another model.
        $lifecycle = 0;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (($frame['object'] ?? null) instanceof ActiveRecord
                && in_array($frame['function'], ['beforeSave', 'afterSave', 'beforeDelete', 'afterDelete'], true)) {
                ++$lifecycle;
            }
        }
        if ($lifecycle > 1) {
            throw new \LogicException('Recursive model writes from lifecycle handlers are unsupported.');
        }
        $db = $model::getDb();
        self::$executing ??= new \WeakMap();
        if (isset(self::$executing[$db])) {
            throw new \LogicException('Recursive audit execution is forbidden.');
        }
        self::$executing[$db] = true;
        try {
            $this->operation = null;
            $this->before = [];
            $this->resolvedSourceType = null;
            if ($db->driverName !== 'pgsql' || $db->slaves !== [] || $db->masters !== []) {
                throw new \LogicException('Use one PostgreSQL Connection without replicas or master pools.');
            }
            $operation = match ($event->name) {
                ActiveRecord::EVENT_BEFORE_INSERT => 'insert',
                ActiveRecord::EVENT_BEFORE_UPDATE => 'update',
                ActiveRecord::EVENT_BEFORE_DELETE => 'delete',
                default => throw new \LogicException('Unexpected audit event.'),
            };
            $flag = match ($operation) {
                'insert' => ActiveRecord::OP_INSERT,
                'update' => ActiveRecord::OP_UPDATE,
                'delete' => ActiveRecord::OP_DELETE,
            };
            $transaction = $db->getTransaction();
            $minimumLevel = $model->isTransactional($flag) ? 2 : 1;
            if ($transaction === null || !$transaction->isActive || $transaction->getLevel() < $minimumLevel) {
                throw new \LogicException('Audited writes require an outer transaction on the model getDb() connection.');
            }
            $schema = $model::getTableSchema();
            $auditSchema = $db->getTableSchema('{{%app_log}}');
            if ($auditSchema === null || $schema->fullName === $auditSchema->fullName) {
                throw new \LogicException('Apply the audit migration first; the audit table cannot audit itself.');
            }
            if ($schema->primaryKey === []) {
                throw new \LogicException('Audited models require a primary key.');
            }
            if ($this->actorProvider !== null && !is_callable($this->actorProvider)) {
                throw new \LogicException('actorProvider must be callable.');
            }
            $this->resolvedSourceType = $this->sourceType;
            if ($this->resolvedSourceType === null && \Yii::$app instanceof \yii\console\Application) {
                $route = \Yii::$app->requestedRoute;
                $this->resolvedSourceType = is_string($route) && $route !== '' ? 'console:' . $route : 'console';
            }
            if ($this->resolvedSourceType !== null
                && ($this->resolvedSourceType === '' || strlen($this->resolvedSourceType) > 255)) {
                throw new \LogicException('sourceType must contain 1..255 bytes.');
            }
            if ($this->deletionMarkAttribute !== null
                && (!isset($schema->columns[$this->deletionMarkAttribute])
                    || in_array($schema->columns[$this->deletionMarkAttribute]->dbType, ['json', 'jsonb'], true))) {
                throw new \LogicException('deletionMarkAttribute must name a scalar column.');
            }
            if ($this->deletionMarkValue !== null && !is_scalar($this->deletionMarkValue)) {
                throw new \LogicException('deletionMarkValue must be scalar or null.');
            }
            if (!array_is_list($this->except)) {
                throw new \LogicException('except accepts a list of column names only.');
            }
            foreach ($this->except as $name) {
                if (!is_string($name) || !isset($schema->columns[$name])) {
                    throw new \LogicException('Unknown excluded audit column.');
                }
            }
            $this->fieldOptions = [];
            foreach ($this->attributes ?: array_keys($schema->columns) as $key => $configuration) {
                $name = is_int($key) ? $configuration : $key;
                if (!is_string($name) || !isset($schema->columns[$name])) {
                    throw new \LogicException('Unknown audit column.');
                }
                $options = is_int($key) ? [] : (is_callable($configuration) ? ['value' => $configuration] : $configuration);
                if (!is_array($options) || array_diff(array_keys($options), ['label', 'value', 'preFilter', 'type', 'model', 'only', 'except', 'functions']) !== []) {
                    throw new \LogicException('Audit options contain unsupported keys.');
                }
                if (array_key_exists('label', $options) && !is_string($options['label'])) {
                    throw new \LogicException('Audit label must be a string.');
                }
                foreach (['value', 'preFilter'] as $callback) {
                    if (array_key_exists($callback, $options) && !is_callable($options[$callback])) {
                        throw new \LogicException('Audit ' . $callback . ' must be callable.');
                    }
                }
                if (isset($options['value']) && in_array($schema->columns[$name]->dbType, ['json', 'jsonb'], true)) {
                    throw new \LogicException('Value callbacks support scalar columns only; JSON is audited losslessly.');
                }
                JsonChanges::validate($options, $schema->columns[$name]->dbType);
                $this->fieldOptions[$name] = $options;
            }
            $this->fields = array_values(array_diff(array_keys($this->fieldOptions), $this->except));
            $this->connection = $db;
            $this->transaction = $transaction;
            $this->pdo = $db->getMasterPdo();
            $this->level = $transaction->getLevel();
            $this->key = $operation === 'insert' ? [] : $model->getOldPrimaryKey(true);
            $this->before = $operation === 'insert' ? [] : $this->snapshot($this->key, true);
            $this->operation = $operation;
        } finally {
            unset(self::$executing[$db]);
        }
    }

    public function afterWrite(Event $event): void
    {
        $model = $this->record();
        $db = $model::getDb();
        $expected = match ($event->name) {
            ActiveRecord::EVENT_AFTER_INSERT => 'insert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'update',
            ActiveRecord::EVENT_AFTER_DELETE => 'delete',
            default => null,
        };
        if ($this->operation === null || $this->operation !== $expected || $db !== $this->connection
            || $db->getTransaction() !== $this->transaction || !$this->transaction->isActive
            || $this->transaction->getLevel() !== $this->level || $db->getMasterPdo() !== $this->pdo) {
            throw new \LogicException('Audit write lost its original connection, transaction, or lifecycle events.');
        }
        if (isset(self::$executing[$db])) {
            throw new \LogicException('Recursive audit execution is forbidden.');
        }
        self::$executing[$db] = true;
        try {
            $operation = $this->operation;
            $old = $this->before;
            $key = $operation === 'delete' ? $this->key : $model->getPrimaryKey(true);
            $new = $operation === 'delete' ? [] : $this->snapshot($key, false);
            $softDelete = false;
            $marker = $this->deletionMarkAttribute;
            if ($operation === 'update' && $marker !== null && $old[$marker]['raw'] !== $new[$marker]['raw']) {
                $column = $model::getTableSchema()->columns[$marker];
                $softDelete = $this->deletionMarkValue === null
                    || $column->phpTypecast($new[$marker]['raw']) === $column->phpTypecast($this->deletionMarkValue);
            }
            if ($marker !== null && !in_array($marker, $this->fields, true)) {
                unset($old[$marker], $new[$marker]);
            }
            if ($operation === 'update' && !$softDelete) {
                foreach ($this->fields as $name) {
                    if (!$this->different($db, $name, $old[$name], $new[$name])) {
                        unset($old[$name], $new[$name]);
                    }
                }
                if ($old === []) {
                    return;
                }
            }
            if ($this->fields === []) {
                return;
            }
            foreach (array_keys($old + $new) as $name) {
                $filter = $this->fieldOptions[$name]['preFilter'] ?? null;
                if ($filter !== null && !$filter($model)) {
                    unset($old[$name], $new[$name]);
                }
            }
            if ($old === [] && $new === []) {
                return;
            }
            $changes = [];
            foreach (array_keys($old + $new) as $name) {
                if (!isset($this->fieldOptions[$name]['type'])) { continue; }
                $builder = new JsonChanges($db, $this->fieldOptions[$name] + ['label' => $model->getAttributeLabel($name)],
                    ($old[$name] ?? $new[$name])['type'] === 'jsonb');
                $changes[$name] = $builder->build($old[$name]['raw'] ?? null, $new[$name]['raw'] ?? null,
                    isset($old[$name]), isset($new[$name]));
            }
            $oldDocument = $this->document($old, $operation === 'delete' ? $changes : []);
            $newDocument = $this->document($new, $operation === 'delete' ? [] : $changes);
            [$contactId, $staffId] = $this->actorIds();
            $logicalType = $this->getUpdatedModelClass();
            $logicalId = $this->getUpdatedModelId();
            if (!is_array($logicalId)) { $logicalId = ['id' => $logicalId]; }
            if ($logicalType === '' || $logicalId === []) { throw new \LogicException('Logical identity must not be empty.'); }
            foreach ($logicalId as $id) {
                if (!is_int($id) && !is_string($id)) { throw new \LogicException('Logical identifiers must be integers or strings.'); }
            }
            $db->createCommand(
                'INSERT INTO {{%app_log}} (entity_type, entity_id, operation, old_values, new_values,
                    contact_id, staff_id, logical_entity_type, logical_entity_id, is_soft_delete, source_type)
                 VALUES (:type, CAST(:key AS jsonb), :operation, CAST(:old AS json), CAST(:new AS json),
                    :contact, :staff, :logical_type, CAST(:logical_id AS jsonb), :soft, :source_type)',
                [
                    ':type' => $model::class,
                    ':key' => json_encode($key, JSON_THROW_ON_ERROR),
                    ':operation' => $softDelete ? 'delete' : $operation,
                    ':old' => $oldDocument,
                    ':new' => $newDocument,
                    ':contact' => $contactId, ':staff' => $staffId,
                    ':logical_type' => $logicalType,
                    ':logical_id' => json_encode($logicalId, JSON_THROW_ON_ERROR),
                    ':soft' => $softDelete, ':source_type' => $this->resolvedSourceType,
                ]
            )->execute();
        } finally {
            $this->operation = null;
            $this->before = [];
            $this->resolvedSourceType = null;
            unset(self::$executing[$db]);
        }
    }

    public function getUpdatedModelClass(): string
    {
        $model = $this->record();
        return method_exists($model, 'getUpdatedModelClass') ? $model->getUpdatedModelClass() : $model::class;
    }

    /** @return int|string|array<string, int|string> */
    public function getUpdatedModelId(): int|string|array
    {
        $model = $this->record();
        return method_exists($model, 'getUpdatedModelId') ? $model->getUpdatedModelId() : $model->getPrimaryKey(true);
    }

    /** @return array{?int, ?int} */
    private function actorIds(): array
    {
        if ($this->actorContactId !== null || $this->actorStaffId !== null) {
            return [$this->actorContactId > 0 ? $this->actorContactId : null, $this->actorStaffId > 0 ? $this->actorStaffId : null];
        }
        $actor = $this->actorProvider === null ? [] : ($this->actorProvider)($this->record());
        if (!is_array($actor) || array_diff(array_keys($actor), ['contact_id', 'staff_id']) !== []) {
            throw new \LogicException('actorProvider must return contact_id/staff_id.');
        }
        foreach ($actor as $id) {
            if ($id !== null && !is_int($id)) { throw new \LogicException('Actor IDs must be integers or null.'); }
        }
        return [($actor['contact_id'] ?? 0) > 0 ? $actor['contact_id'] : null,
            ($actor['staff_id'] ?? 0) > 0 ? $actor['staff_id'] : null];
    }

    /** @param array<string, mixed> $key
     * @return Snapshot
     */
    private function snapshot(array $key, bool $lock): array
    {
        $model = $this->record();
        $db = $model::getDb();
        $select = ['_exists' => new Expression('1')];
        $fields = array_values(array_unique([...$this->fields, ...($this->deletionMarkAttribute === null ? [] : [$this->deletionMarkAttribute])]));
        foreach ($fields as $i => $name) {
            $column = $db->quoteColumnName($name);
            $select['t' . $i] = new Expression("pg_typeof($column)::text");
            $select['r' . $i] = new Expression("$column::text");
            $select['j' . $i] = new Expression("json_build_object('sql_null', $column IS NULL, 'value', $column, 'raw', $column::text)::text");
        }
        $query = (new Query())->select($select)->from($model::tableName())->where($key);
        [$sql, $params] = $db->getQueryBuilder()->build($query);
        $row = $db->createCommand($sql . ($lock ? ' FOR UPDATE' : ''), $params)->queryOne();
        if ($row === false) {
            throw new \RuntimeException('Audited row was not found; roll back the transaction.');
        }
        $result = [];
        foreach ($fields as $i => $name) {
            $result[$name] = ['type' => (string) $row['t' . $i], 'raw' => $row['r' . $i] === null ? null : (string) $row['r' . $i], 'json' => (string) $row['j' . $i]];
        }
        return $result;
    }

    /** @param array{type: string, raw: ?string, json: string} $old
     * @param array{type: string, raw: ?string, json: string} $new
     */
    private function different(Connection $db, string $name, array $old, array $new): bool
    {
        if ($old['type'] === 'jsonb') {
            return (bool) $db->createCommand(
                'SELECT CAST(:old AS jsonb) IS DISTINCT FROM CAST(:new AS jsonb)',
                [':old' => $old['raw'], ':new' => $new['raw']]
            )->queryScalar();
        }
        if ($old['type'] === 'json') {
            return $old['raw'] !== $new['raw'];
        }
        // Keep the original scalar comparison: cast new to old's PHP type, then loose comparison.
        $column = $this->record()::getTableSchema()->columns[$name];
        $before = $column->phpTypecast($old['raw']);
        $after = $column->phpTypecast($new['raw']);
        if (is_scalar($before)) {
            settype($after, gettype($before));
        }
        return $before != $after;
    }

    /** @param Snapshot $snapshot
     * @param array<string, string> $changes */
    private function document(array $snapshot, array $changes = []): string
    {
        $entries = [];
        foreach ($snapshot as $field => $value) {
            $options = $this->fieldOptions[$field];
            $presentation = '';
            if ($options !== []) {
                $presentation = ',"label":' . json_encode($options['label'] ?? $this->record()->getAttributeLabel($field), JSON_THROW_ON_ERROR);
                if (isset($options['value'])) {
                    $column = $this->record()::getTableSchema()->columns[$field];
                    $display = $options['value']($column->phpTypecast($value['raw']));
                    $presentation .= ',"display_value":' . json_encode($display, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                }
            }
            if (isset($changes[$field])) { $presentation .= ',"changes":' . $changes[$field]; }
            $entries[] = json_encode($field, JSON_THROW_ON_ERROR) . ':{"db_type":'
                . json_encode($value['type'], JSON_THROW_ON_ERROR) . ',"data":' . $value['json'] . $presentation . '}';
        }
        return '{' . implode(',', $entries) . '}';
    }
}
