<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog\Tests;

use Ktulchu\Yii2AppLog\AppLogBehavior;
use Ktulchu\Yii2AppLog\History;
use Ktulchu\Yii2AppLog\RawJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\console\Application;
use yii\db\ActiveRecord;
use yii\db\Connection;

final class AuditTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $dsn = getenv('APPLOG_TEST_DSN');
        if (!$dsn) {
            self::fail('Set APPLOG_TEST_DSN to a disposable PostgreSQL database; tests never silently skip.');
        }
        $this->db = new Connection([
            'dsn' => $dsn,
            'username' => getenv('APPLOG_TEST_USER') ?: 'audit',
            'password' => getenv('APPLOG_TEST_PASSWORD') ?: 'audit',
        ]);
        Fixture::$db = $this->db;
        Fixture::$configuration = ['except' => ['secret']];
        Fixture::$transactional = false;
        new Application([
            'id' => 'audit-test', 'basePath' => __DIR__,
            'components' => ['db' => [
                'class' => Connection::class, 'dsn' => 'pgsql:host=invalid;dbname=never',
            ]],
        ]);
        $this->db->open();
        $this->db->createCommand('DROP TABLE IF EXISTS fixture, app_log, department CASCADE')->execute();
        $this->db->createCommand('DROP SEQUENCE IF EXISTS write_probe CASCADE')->execute();
        require_once dirname(__DIR__) . '/migrations/m261117_000001_create_app_log.php';
        require_once dirname(__DIR__) . '/migrations/m261117_000003_add_app_log_context.php';
        require_once dirname(__DIR__) . '/migrations/m261118_000004_add_app_log_source_type.php';
        ob_start();
        try {
            self::assertNotFalse((new \m261117_000001_create_app_log(['db' => $this->db]))->up());
            self::assertNotFalse((new \m261117_000003_add_app_log_context(['db' => $this->db]))->up());
            self::assertNotFalse((new \m261118_000004_add_app_log_source_type(['db' => $this->db]))->up());
        } finally {
            ob_end_clean();
        }
        $this->db->createCommand('CREATE TABLE fixture (
            id bigserial PRIMARY KEY, title text, secret text,
            document json, binary_document jsonb, flag boolean, amount integer
        )')->execute();
        $this->db->schema->refresh();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $tx = $this->db->getTransaction();
            if ($tx !== null && $tx->isActive) {
                $tx->rollBack();
            }
            $this->db->close();
        }
        if (\Yii::$app !== null) {
            \Yii::$app->errorHandler->unregister();
            \Yii::$app = null;
        }
    }

    private function atomic(callable $work): mixed
    {
        $tx = $this->db->beginTransaction();
        try {
            $result = $work();
            $tx->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            throw $e;
        }
    }

    private function record(): Fixture
    {
        $r = new Fixture();
        $r->setAttributes(['title' => 'first', 'secret' => 'excluded', 'flag' => false, 'amount' => 0], false);
        return $r;
    }

    private function seed(): Fixture
    {
        $r = $this->record();
        $this->atomic(fn () => $r->save(false));
        return $r;
    }

    private function rows(): array
    {
        return $this->db->createCommand('SELECT * FROM app_log ORDER BY id')->queryAll();
    }

    public function testCrudRepeatNoopHistoryAndTypes(): void
    {
        $r = $this->record();
        $this->atomic(function () use ($r): void {
            self::assertTrue($r->save(false));
            self::assertTrue($r->save(false));
            $r->refresh();
            self::assertTrue($r->save(false));
            self::assertCount(1, $this->rows());
            $r->setAttribute('title', 'second');
            self::assertTrue($r->save(false));
            self::assertCount(2, $this->rows());
            self::assertTrue($r->save(false));
            self::assertCount(2, $this->rows());
            self::assertSame(1, $r->delete());
        });
        $rows = History::read($this->db, Fixture::class, $r->getPrimaryKey(true));
        self::assertSame(['insert', 'update', 'delete'], array_column($rows, 'operation'));
        $insert = json_decode($rows[0]['new_values'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(false, $insert['flag']['data']['value']);
        self::assertSame(0, $insert['amount']['data']['value']);
        self::assertArrayNotHasKey('secret', $insert);
        self::assertSame('{}', $rows[0]['old_values']);
        self::assertSame('{}', $rows[2]['new_values']);
        self::assertSame(['title'], array_keys(json_decode($rows[1]['new_values'], true)));
        self::assertCount(1, History::read($this->db, Fixture::class, $r->getPrimaryKey(true), 1, (int) $rows[0]['id']));
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    public static function jsonValues(): iterable
    {
        foreach (['document', 'binary_document'] as $column) {
            foreach ([
                'null', '{}', '[]', 'false', '0', '""',
                '1234567890123456789012345678901234567890',
                '{"nested":{"empty":{},"list":[[],false,0,null,"",{"x":1}]}}',
                '{"unicode":"Привет","escaped":"\\u0061"}',
                '{"n":12345678901234567890.123456789012345678901}',
            ] as $index => $value) {
                yield "$column-$index" => [$column, $value];
            }
        }
    }

    #[DataProvider('jsonValues')]
    public function testJsonValuesAreLossless(string $column, string $raw): void
    {
        $r = $this->record();
        $r->setAttribute($column, RawJson::value($raw, $column === 'binary_document'));
        $this->atomic(fn () => $r->save(false));
        $stored = $this->db->createCommand("SELECT $column::text FROM fixture")->queryScalar();
        $data = json_decode($this->rows()[0]['new_values'], true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        self::assertFalse($data[$column]['data']['sql_null']);
        self::assertSame($stored, $data[$column]['data']['raw']);
        if ($column === 'document') {
            self::assertSame($raw, $stored);
        }
        // Check numeric precision and native type in PostgreSQL rather than decoding to PHP float.
        $matches = $this->db->createCommand(
            "SELECT (new_values->:field->'data'->'value')::text = (SELECT $column::text FROM fixture) FROM app_log",
            [':field' => $column]
        )->queryScalar();
        self::assertTrue((bool) $matches);
        $r->refresh();
        $this->atomic(fn () => $r->save(false));
        self::assertCount(1, $this->rows());
        $r->setAttribute($column, RawJson::sqlNull());
        $this->atomic(fn () => $r->save(false));
        $change = json_decode($this->rows()[1]['new_values'], true);
        self::assertTrue($change[$column]['data']['sql_null']);
        self::assertNull($change[$column]['data']['value']);
        self::assertNull($change[$column]['data']['raw']);
        $r->setAttribute($column, RawJson::value('null', $column === 'binary_document'));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(3, $this->rows());
        self::assertFalse(json_decode($this->rows()[2]['new_values'], true)[$column]['data']['sql_null']);
    }

    public function testJsonTextAndJsonbPostgresSemantics(): void
    {
        $r = $this->record();
        $r->setAttribute('document', RawJson::value('{"a":1,"b":"a"}'));
        $r->setAttribute('binary_document', RawJson::value('{"a":1,"b":"a"}', true));
        $this->atomic(fn () => $r->save(false));
        $r->setAttribute('document', RawJson::value('{ "b":"\\u0061", "a":1.0 }'));
        $r->setAttribute('binary_document', RawJson::value('{ "b":"\\u0061", "a":1.0 }', true));
        $this->atomic(fn () => $r->save(false));
        $change = json_decode($this->rows()[1]['new_values'], true);
        self::assertSame(['document'], array_keys($change));
        $r->setAttribute('binary_document', RawJson::value('{"a":1,"b":"a"}', true));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
        $r->setAttribute('binary_document', RawJson::value('[2,1]', true));
        $this->atomic(fn () => $r->save(false));
        $r->setAttribute('binary_document', RawJson::value('[1,2]', true));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(4, $this->rows());
    }

    public static function operations(): iterable
    {
        yield 'insert' => ['insert'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
    }

    private function write(Fixture $r, string $operation): void
    {
        if ($operation === 'delete') {
            $r->delete();
        } else {
            $r->setAttribute('title', 'changed');
            $r->save(false);
        }
    }

    public static function transactionModes(): iterable
    {
        foreach (['insert', 'update', 'delete'] as $operation) {
            yield $operation . '-plain' => [$operation, false];
            yield $operation . '-yii-transactional' => [$operation, true];
        }
    }

    #[DataProvider('transactionModes')]
    public function testNoTransactionFailsBeforeAnyBusinessSql(string $operation, bool $yiiTransactional): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        Fixture::$transactional = $yiiTransactional;
        $count = count($this->rows());
        // Sequence increments survive rollback: even a temporary attempted row write is detectable.
        $this->db->createCommand('CREATE SEQUENCE write_probe')->execute();
        $this->db->createCommand("CREATE OR REPLACE FUNCTION probe_write() RETURNS trigger LANGUAGE plpgsql AS
            'BEGIN PERFORM nextval(''write_probe''); RETURN NULL; END'")->execute();
        $this->db->createCommand('CREATE TRIGGER probe BEFORE INSERT OR UPDATE OR DELETE ON fixture FOR EACH STATEMENT EXECUTE FUNCTION probe_write()')->execute();
        try {
            $this->write($r, $operation);
            self::fail('Expected fail-fast.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('outer transaction', $e->getMessage());
        }
        self::assertFalse((bool) $this->db->createCommand('SELECT is_called FROM write_probe')->queryScalar());
        self::assertCount($count, $this->rows());
        self::assertSame($operation === 'insert' ? 0 : 1, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    #[DataProvider('operations')]
    public function testAuditFailureRollsBackBusinessOperation(string $operation): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        $before = $this->db->createCommand('SELECT * FROM fixture')->queryAll();
        $count = count($this->rows());
        $this->db->createCommand("ALTER TABLE app_log ADD CONSTRAINT force_failure CHECK (operation <> '$operation') NOT VALID")->execute();
        try {
            $this->atomic(fn () => $this->write($r, $operation));
            self::fail('Audit error must propagate.');
        } catch (\yii\db\IntegrityException $e) {
            self::assertStringContainsString('force_failure', $e->getMessage());
        }
        self::assertSame($before, $this->db->createCommand('SELECT * FROM fixture')->queryAll());
        self::assertCount($count, $this->rows());
    }

    #[DataProvider('operations')]
    public function testCallerRollback(string $operation): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        $before = $this->db->createCommand('SELECT * FROM fixture')->queryAll();
        $count = count($this->rows());
        $tx = $this->db->beginTransaction();
        $this->write($r, $operation);
        self::assertTrue($tx->isActive);
        self::assertSame(1, $tx->getLevel());
        self::assertCount($count + 1, $this->rows());
        $tx->rollBack();
        self::assertSame($before, $this->db->createCommand('SELECT * FROM fixture')->queryAll());
        self::assertCount($count, $this->rows());
    }

    public function testCustomConnectionNoAdditionalConnections(): void
    {
        $pdo = $this->db->getMasterPdo();
        $opens = 0;
        $countOpen = static function () use (&$opens): void { ++$opens; };
        \yii\base\Event::on(Connection::class, Connection::EVENT_AFTER_OPEN, $countOpen);
        $pid = $this->db->createCommand('SELECT pg_backend_pid()')->queryScalar();
        $this->db->createCommand("ALTER TABLE app_log ADD COLUMN backend_pid integer DEFAULT pg_backend_pid()")->execute();
        try {
            $r = $this->seed();
            $r->setAttribute('title', 'new');
            $this->atomic(fn () => $r->save(false));
        } finally {
            \yii\base\Event::off(Connection::class, Connection::EVENT_AFTER_OPEN, $countOpen);
        }
        self::assertSame([$pid, $pid], array_column($this->rows(), 'backend_pid'));
        self::assertSame($pdo, $this->db->getMasterPdo());
        self::assertSame(0, $opens);
        self::assertFalse(\Yii::$app->db->isActive);
    }

    public function testYiiAutomaticTransactionDoesNotReplaceOuterTransaction(): void
    {
        Fixture::$transactional = true;
        $r = $this->record();
        try {
            $r->save(false);
            self::fail('A Yii-owned transaction is insufficient.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('outer transaction', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
        $this->atomic(function () use ($r): void {
            self::assertTrue($r->save(false));
            self::assertSame(1, $this->db->getTransaction()->getLevel());
        });
        self::assertCount(1, $this->rows());
    }

    public function testExcludedAttributesAndWhitelist(): void
    {
        Fixture::$configuration = ['attributes' => ['title', 'secret'], 'except' => ['secret']];
        $r = $this->seed();
        $r->setAttribute('secret', 'another');
        $this->atomic(fn () => $r->save(false));
        self::assertCount(1, $this->rows());
        self::assertSame(['title'], array_keys(json_decode($this->rows()[0]['new_values'], true)));
    }

    public function testPrimaryKeyChangesAndPartialSave(): void
    {
        $r = $this->seed();
        $r->setAttribute('id', 100);
        $r->setAttribute('title', 'unsaved');
        $this->atomic(fn () => $r->save(false, ['id']));
        self::assertSame(['id' => 100], json_decode($this->rows()[1]['entity_id'], true));
        self::assertSame(['id'], array_keys(json_decode($this->rows()[1]['new_values'], true)));
    }

    public function testRecursiveLifecycleSaveIsRejected(): void
    {
        $r = $this->record();
        $r->on(ActiveRecord::EVENT_AFTER_INSERT, function (): void {
            $this->record()->save(false);
        });
        try {
            $this->atomic(fn () => $r->save(false));
            self::fail('Recursive write must fail.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Recursive', $e->getMessage());
        }
        self::assertCount(0, $this->rows());
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    public function testCancelThenRetryDoesNotKeepStaleState(): void
    {
        $r = $this->record();
        $cancel = static function ($event): void { $event->isValid = false; };
        $r->on(ActiveRecord::EVENT_BEFORE_INSERT, $cancel);
        $this->atomic(fn () => self::assertFalse($r->save(false)));
        $r->off(ActiveRecord::EVENT_BEFORE_INSERT, $cancel);
        $this->atomic(fn () => self::assertTrue($r->save(false)));
        self::assertCount(1, $this->rows());
    }

    public function testAuditTableCannotAuditItself(): void
    {
        $r = new AuditFixture();
        try {
            $this->atomic(fn () => $r->save(false));
            self::fail('Self-audit must fail.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('cannot audit itself', $e->getMessage());
        }
        self::assertCount(0, $this->rows());
    }

    public function testUnsupportedJsonProjectionFailsBeforeInsert(): void
    {
        Fixture::$configuration = ['attributes' => ['document' => ['only' => ['nested']]]];
        try {
            $this->atomic(fn () => $this->record()->save(false));
            self::fail('Unsupported legacy options must not be silently ignored.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('JSON', $e->getMessage());
        }
        self::assertCount(0, $this->rows());
    }

    public function testLabelValueAndPrefilterWithRelatedModelLookup(): void
    {
        $this->db->createCommand('CREATE TABLE department (id integer PRIMARY KEY, name text)')->execute();
        $this->db->createCommand()->batchInsert('department', ['id', 'name'], [[10, 'Sales'], [20, 'Support']])->execute();
        Fixture::$configuration = ['attributes' => [
            'amount' => [
                'label' => 'Подразделение',
                'value' => function ($id) { return Department::findOne($id)->getAttribute('name') ?? null; },
                'preFilter' => function ($model) { return !$model->getIsArchived(); },
            ],
        ]];
        $r = $this->record();
        $r->setAttribute('amount', 10);
        $this->atomic(fn () => $r->save(false));
        $entry = json_decode($this->rows()[0]['new_values'], true)['amount'];
        self::assertSame('Подразделение', $entry['label']);
        self::assertSame('Sales', $entry['display_value']);
        self::assertSame(10, $entry['data']['value']);
        self::assertSame('10', $entry['data']['raw']);
        $r->setAttribute('amount', 20);
        $this->atomic(fn () => $r->save(false));
        self::assertSame('Sales', json_decode($this->rows()[1]['old_values'], true)['amount']['display_value']);
        self::assertSame('Support', json_decode($this->rows()[1]['new_values'], true)['amount']['display_value']);
        self::assertSame(10, json_decode($this->rows()[1]['old_values'], true)['amount']['data']['value']);
        $r->setAttribute('flag', true);
        $r->setAttribute('amount', 10);
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
        self::assertSame(10, (int) $this->db->createCommand('SELECT amount FROM fixture')->queryScalar());
        $r->setAttribute('flag', false);
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
        $this->atomic(fn () => $r->delete());
        self::assertSame('Sales', json_decode($this->rows()[2]['old_values'], true)['amount']['display_value']);
        self::assertSame('{}', $this->rows()[2]['new_values']);
    }

    public function testMixedAttributeListShorthandCallbackAndLabelOnly(): void
    {
        $calls = [];
        Fixture::$configuration = ['attributes' => [
            'title',
            'amount' => static function ($value) use (&$calls) { $calls[] = $value; return $value === null ? null : "Value $value"; },
            'flag' => ['label' => 'Flag'],
        ]];
        $r = $this->record();
        $r->setAttribute('amount', null);
        $this->atomic(fn () => $r->save(false));
        $entry = json_decode($this->rows()[0]['new_values'], true);
        self::assertArrayNotHasKey('label', $entry['title']);
        self::assertSame('Flag', $entry['flag']['label']);
        self::assertFalse($entry['flag']['data']['value']);
        self::assertArrayHasKey('display_value', $entry['amount']);
        self::assertNull($entry['amount']['display_value']);
        self::assertSame([null], $calls);
        $r->setAttribute('amount', 7);
        $this->atomic(fn () => $r->save(false));
        self::assertSame([null, null, 7], $calls);
        self::assertNull(json_decode($this->rows()[1]['old_values'], true)['amount']['display_value']);
        self::assertSame('Value 7', json_decode($this->rows()[1]['new_values'], true)['amount']['display_value']);
        $this->atomic(fn () => $r->save(false));
        self::assertSame([null, null, 7], $calls);
    }

    #[DataProvider('operations')]
    public function testCallbackFailurePropagatesAndRollsBack(string $operation): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        $before = $this->db->createCommand('SELECT * FROM fixture')->queryAll();
        $count = count($this->rows());
        $r->getBehavior('audit')->attributes = ['title' => ['value' => static function () { throw new \RuntimeException('callback failed'); }]];
        try {
            $this->atomic(fn () => $this->write($r, $operation));
            self::fail('Callback errors must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('callback failed', $e->getMessage());
        }
        self::assertSame($before, $this->db->createCommand('SELECT * FROM fixture')->queryAll());
        self::assertCount($count, $this->rows());
    }

    public function testPrefilterAndExceptSkipValueCallbacks(): void
    {
        $calls = 0;
        Fixture::$configuration = ['attributes' => [
            'title' => ['preFilter' => static fn ($model) => false, 'value' => static function () { throw new \RuntimeException('must not run'); }],
            'amount' => ['value' => static function () { throw new \RuntimeException('excluded must not run'); }],
            'flag' => ['preFilter' => static function ($model) use (&$calls) { ++$calls; return true; }],
        ], 'except' => ['amount']];
        $r = $this->seed();
        self::assertSame(1, $calls);
        self::assertSame(['flag'], array_keys(json_decode($this->rows()[0]['new_values'], true)));
        $r->setAttribute('title', 'second');
        $this->atomic(fn () => $r->save(false));
        self::assertSame(1, $calls);
        self::assertCount(1, $this->rows());
        $this->atomic(fn () => $r->delete());
        self::assertSame(2, $calls);
    }

    public static function invalidOptions(): iterable
    {
        yield 'label type' => [['label' => 7]];
        yield 'value type' => [['value' => 'not_a_real_callback']];
        yield 'filter type' => [['preFilter' => false]];
        yield 'unknown option' => [['unknown' => true]];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidCallbackOptionsFailBeforeSql(array $options): void
    {
        Fixture::$configuration = ['attributes' => ['title' => $options]];
        try {
            $this->atomic(fn () => $this->record()->save(false));
            self::fail('Invalid options must fail.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Audit', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    public function testJsonLabelAndFilterPreserveRawAndRejectValueCallback(): void
    {
        Fixture::$configuration = ['attributes' => [
            'document' => ['label' => 'Document', 'preFilter' => static fn ($model) => true],
        ]];
        $r = $this->record();
        $r->setAttribute('document', RawJson::value('{"n":123456789012345678901234567890}'));
        $this->atomic(fn () => $r->save(false));
        $entry = json_decode($this->rows()[0]['new_values'], true, 512, JSON_BIGINT_AS_STRING)['document'];
        self::assertSame('Document', $entry['label']);
        self::assertSame('{"n":123456789012345678901234567890}', $entry['data']['raw']);
        self::assertArrayNotHasKey('display_value', $entry);
        $r->getBehavior('audit')->attributes = ['document' => ['value' => static fn ($value) => $value]];
        try {
            $this->atomic(fn () => $r->save(false));
            self::fail('JSON value projection must be rejected.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('scalar', $e->getMessage());
        }
        self::assertCount(1, $this->rows());
    }

    public function testRecursiveWriteFromCallbackRollsBack(): void
    {
        Fixture::$configuration = ['attributes' => ['title' => ['value' => function () { $this->record()->save(false); }]]];
        try {
            $this->atomic(fn () => $this->record()->save(false));
            self::fail('Recursive callback writes must fail.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Recursive', $e->getMessage());
        }
        self::assertCount(0, $this->rows());
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    #[DataProvider('operations')]
    public function testPrefilterFailurePropagatesAndRollsBack(string $operation): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        $before = $this->db->createCommand('SELECT * FROM fixture')->queryAll();
        $count = count($this->rows());
        $r->getBehavior('audit')->attributes = ['title' => ['preFilter' => static function () { throw new \RuntimeException('filter failed'); }]];
        try {
            $this->atomic(fn () => $this->write($r, $operation));
            self::fail('Filter errors must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('filter failed', $e->getMessage());
        }
        self::assertSame($before, $this->db->createCommand('SELECT * FROM fixture')->queryAll());
        self::assertCount($count, $this->rows());
    }

    #[DataProvider('operations')]
    public function testAllFilteredFieldsDoNotCreateHistory(string $operation): void
    {
        $r = $operation === 'insert' ? $this->record() : $this->seed();
        $count = count($this->rows());
        $r->getBehavior('audit')->attributes = ['title' => ['preFilter' => static fn ($model) => false]];
        $this->atomic(fn () => $this->write($r, $operation));
        self::assertCount($count, $this->rows());
        if ($operation === 'delete') {
            self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
        } else {
            self::assertSame('changed', $this->db->createCommand('SELECT title FROM fixture')->queryScalar());
        }
    }

    public function testCallbackOutputDoesNotControlChangeDetection(): void
    {
        Fixture::$configuration = ['attributes' => ['title' => static fn ($value) => 'same display']];
        $r = $this->seed();
        $r->setAttribute('title', 'second');
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
        self::assertSame('first', json_decode($this->rows()[1]['old_values'], true)['title']['data']['value']);
        self::assertSame('second', json_decode($this->rows()[1]['new_values'], true)['title']['data']['value']);
        self::assertSame('same display', json_decode($this->rows()[1]['old_values'], true)['title']['display_value']);
        self::assertSame('same display', json_decode($this->rows()[1]['new_values'], true)['title']['display_value']);
    }

    public function testUnserializableCallbackResultRollsBack(): void
    {
        Fixture::$configuration = ['attributes' => ['title' => static fn ($value) => NAN]];
        try {
            $this->atomic(fn () => $this->record()->save(false));
            self::fail('Unserializable callback output must fail.');
        } catch (\JsonException $e) {
            self::assertSame(JSON_ERROR_INF_OR_NAN, $e->getCode());
        }
        self::assertCount(0, $this->rows());
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM fixture')->queryScalar());
    }

    public function testLargeJsonbNumbersCompareInPostgresWithoutFloatRounding(): void
    {
        $r = $this->record();
        $r->setAttribute('binary_document', RawJson::value('{"n":123456789012345678901234567890}', true));
        $this->atomic(fn () => $r->save(false));
        $r->setAttribute('binary_document', RawJson::value('{"n":123456789012345678901234567890.000}', true));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(1, $this->rows());
        $r->setAttribute('binary_document', RawJson::value('{"n":123456789012345678901234567891}', true));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
        self::assertStringContainsString('123456789012345678901234567891', $this->rows()[1]['new_values']);
    }

    public function testImmediateTriggerSnapshotAndJsonDuplicateKeys(): void
    {
        $this->db->createCommand("CREATE OR REPLACE FUNCTION normalize_title() RETURNS trigger LANGUAGE plpgsql AS
            'BEGIN NEW.title := upper(NEW.title); RETURN NEW; END'")->execute();
        $this->db->createCommand('CREATE TRIGGER normalize BEFORE INSERT OR UPDATE ON fixture FOR EACH ROW EXECUTE FUNCTION normalize_title()')->execute();
        $r = $this->record();
        $r->setAttribute('document', RawJson::value('{"x":1,"x":2}'));
        $this->atomic(fn () => $r->save(false));
        $values = json_decode($this->rows()[0]['new_values'], true);
        self::assertSame('FIRST', $values['title']['data']['value']);
        self::assertSame('{"x":1,"x":2}', $values['document']['data']['raw']);
        $r->setAttribute('document', RawJson::value('{"x":2}'));
        $this->atomic(fn () => $r->save(false));
        self::assertCount(2, $this->rows());
    }

    public function testCompositePrimaryKeyAndTablePrefix(): void
    {
        $this->db->tablePrefix = 'pref_';
        require_once dirname(__DIR__) . '/migrations/m261117_000001_create_app_log.php';
        ob_start();
        try {
            self::assertNotFalse((new \m261117_000001_create_app_log(['db' => $this->db]))->up());
            self::assertNotFalse((new \m261117_000003_add_app_log_context(['db' => $this->db]))->up());
        } finally { ob_end_clean(); }
        $this->db->createCommand('CREATE TABLE pref_pair (tenant text, code integer, title text, PRIMARY KEY (tenant, code))')->execute();
        $r = new PairFixture();
        $r->setAttributes(['tenant' => 'a', 'code' => 2, 'title' => 'first'], false);
        $this->atomic(fn () => $r->save(false));
        $r->setAttribute('title', 'second');
        $this->atomic(fn () => $r->save(false));
        $this->atomic(fn () => $r->delete());
        self::assertCount(3, History::read($this->db, PairFixture::class, ['tenant' => 'a', 'code' => 2]));
        $this->db->createCommand('DROP TABLE pref_pair, pref_app_log')->execute();
        $this->db->tablePrefix = '';
    }
}

class Fixture extends ActiveRecord
{
    public static Connection $db;
    public static array $configuration = [];
    public static bool $transactional = false;
    public static function tableName(): string { return 'fixture'; }
    public static function getDb(): Connection { return self::$db; }
    public function behaviors(): array
    {
        return ['audit' => ['class' => AppLogBehavior::class] + self::$configuration];
    }
    public function transactions(): array
    {
        return self::$transactional ? [$this->getScenario() => self::OP_ALL] : [];
    }
    public function getIsArchived(): bool { return (bool) $this->getAttribute('flag'); }
}

final class Department extends Fixture
{
    public static function tableName(): string { return 'department'; }
    public function behaviors(): array { return []; }
}

final class AuditFixture extends Fixture
{
    public static function tableName(): string { return 'app_log'; }
}

final class PairFixture extends Fixture
{
    public static function tableName(): string { return '{{%pair}}'; }
    public function behaviors(): array { return ['audit' => ['class' => AppLogBehavior::class]]; }
}
