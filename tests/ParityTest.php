<?php
declare(strict_types=1);

namespace Ktulchu\Yii2AppLog\Tests;

use Ktulchu\Yii2AppLog\AppLogBehavior;
use Ktulchu\Yii2AppLog\AppLogHelper;
use Ktulchu\Yii2AppLog\History;
use Ktulchu\Yii2AppLog\RawJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\Model;
use yii\console\Application;
use yii\db\ActiveRecord;
use yii\db\Connection;

final class ParityTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!getenv('APPLOG_TEST_DSN')) { self::fail('A disposable PostgreSQL DSN is required.'); }
        $this->db = new Connection([
            'dsn' => getenv('APPLOG_TEST_DSN'),
            'username' => getenv('APPLOG_TEST_USER') ?: 'audit',
            'password' => getenv('APPLOG_TEST_PASSWORD') ?: 'audit',
        ]);
        ParityRecord::$db = $this->db;
        ParityRecord::$config = [];
        new Application(['id' => 'parity-cli', 'basePath' => __DIR__, 'components' => [
            'user' => static function () { throw new \RuntimeException('Library must not access the session.'); },
        ]]);
        $this->db->createCommand('DROP TABLE IF EXISTS parity_fixture, app_log CASCADE')->execute();
        require_once dirname(__DIR__) . '/migrations/m261117_000001_create_app_log.php';
        require_once dirname(__DIR__) . '/migrations/m261117_000003_add_app_log_context.php';
        ob_start();
        try {
            self::assertNotFalse((new \m261117_000001_create_app_log(['db' => $this->db]))->up());
            self::assertNotFalse((new \m261117_000003_add_app_log_context(['db' => $this->db]))->up());
        } finally { ob_end_clean(); }
        $this->db->createCommand('CREATE TABLE parity_fixture (
            id serial PRIMARY KEY, title text, document json, binary_document jsonb,
            is_deleted boolean NOT NULL DEFAULT false, status integer NOT NULL DEFAULT 0
        )')->execute();
        $this->db->schema->refresh();
    }

    protected function tearDown(): void
    {
        $transaction = $this->db->getTransaction();
        if ($transaction !== null && $transaction->isActive) { $transaction->rollBack(); }
        $this->db->close();
        \Yii::$app->errorHandler->unregister();
        \Yii::$app = null;
    }

    private function atomic(callable $work): mixed
    {
        $transaction = $this->db->beginTransaction();
        try {
            $result = $work();
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive) { $transaction->rollBack(); }
            throw $error;
        }
    }

    private function record(string $class = ParityRecord::class): ParityRecord
    {
        $model = new $class();
        $model->setAttribute('title', 'First');
        return $model;
    }

    private function rows(): array
    {
        return $this->db->createCommand('SELECT * FROM app_log ORDER BY id')->queryAll();
    }

    private function values(int $index, string $side = 'new'): array
    {
        return json_decode($this->rows()[$index][$side . '_values'], true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    public static function formats(): iterable
    {
        foreach (AppLogHelper::JSON_TYPES as $format) {
            foreach (['document', 'binary_document'] as $column) {
                yield $format . '-' . $column => [$format, $column];
            }
        }
    }

    #[DataProvider('formats')]
    public function testAllFourFormatsPreserveRawAndOnlyPresentChanges(string $format, string $column): void
    {
        $collection = $format === AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION;
        $simple = $format === AppLogHelper::JSON_SINGLE_SIMPLE_ARRAY;
        $before = $collection ? '[{"userId":1,"secret":"x"},{"userId":2,"secret":"y"}]'
            : ($simple ? '[false,0,{},[]]' : '{"userId":1,"same":false,"floor":{"from":0,"to":5}}');
        $after = $collection ? '[{"userId":2,"secret":"y"},{"userId":1,"secret":"x"}]'
            : ($simple ? '[0,false,{},[]]' : '{"userId":2,"same":false,"floor":{"from":0,"to":6}}');
        ParityRecord::$config = ['attributes' => [$column => ['type' => $format, 'model' => JsonLabels::class]]];
        $model = $this->record();
        $model->setAttribute($column, RawJson::value($before, $column === 'binary_document'));
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute($column, RawJson::value($after, $column === 'binary_document'));
        $this->atomic(fn () => $model->save(false));
        $new = $this->values(1)[$column];
        if ($column === 'document') {
            self::assertSame($before, $this->values(1, 'old')[$column]['data']['raw']);
            self::assertSame($after, $new['data']['raw']);
        }
        if ($simple || $collection) {
            self::assertCount(1, $new['changes']);
            self::assertSame([], $new['changes'][0]['path']);
            self::assertEquals(json_decode($after, true), $new['changes'][0]['new']['value']);
        } else {
            $byPath = [];
            foreach ($new['changes'] as $change) { $byPath[implode('.', $change['path'])] = $change; }
            self::assertCount(2, $byPath);
            self::assertSame('User', $byPath['userId']['label']);
            self::assertSame('Floor / To', $byPath['floor.to']['label']);
            self::assertSame(1, $byPath['userId']['old']['value']);
            self::assertSame(2, $byPath['userId']['new']['value']);
        }
        $model->refresh();
        $this->atomic(fn () => $model->save(false));
        self::assertCount(2, $this->rows());
        $this->atomic(fn () => $model->delete());
        self::assertArrayHasKey('changes', $this->values(2, 'old')[$column]);
        self::assertSame([], $this->values(2));
    }

    public function testObjectFiltersCallbacksLabelsAndFrozenDisplay(): void
    {
        $names = [1 => 'Alice', 2 => 'Bob'];
        $calls = [];
        ParityRecord::$config = ['attributes' => ['document' => [
            'type' => AppLogHelper::JSON_SINGLE_COMPLEX_ARRAY, 'model' => JsonLabels::class,
            'only' => ['userId', 'secret'], 'except' => ['secret'],
            'functions' => ['userId' => static function ($id) use (&$names, &$calls) {
                $calls[] = $id; return $names[$id] ?? null;
            }, 'secret' => static function () { throw new \RuntimeException('excluded'); }],
        ]]];
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('{"userId":1,"secret":"before","same":0}'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame([1], $calls);
        $model->setAttribute('document', RawJson::value('{"userId":2,"secret":"after","same":0}'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame([1, 1, 2], $calls);
        $change = $this->values(1)['document']['changes'];
        self::assertCount(1, $change);
        self::assertSame('Alice', $change[0]['old']['display_value']);
        self::assertSame('Bob', $change[0]['new']['display_value']);
        self::assertSame(2, $change[0]['new']['value']);
        self::assertStringContainsString('"secret":"after"', $this->values(1)['document']['data']['raw']);
        $names[2] = 'Renamed';
        self::assertSame('Bob', $this->values(1)['document']['changes'][0]['new']['display_value']);
        $model->setAttribute('document', RawJson::value('{"userId":2,"secret":"third","same":0}'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame([], $this->values(2)['document']['changes']);
        self::assertSame([1, 1, 2], $calls);
    }

    public function testCollectionProjectionDoesNotMatchRecordsOrAffectSnapshots(): void
    {
        $calls = [];
        ParityRecord::$config = ['attributes' => ['document' => [
            'type' => AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION, 'model' => JsonLabels::class,
            'only' => ['userId', 'secret'], 'except' => ['secret'],
            'functions' => ['userId' => static function ($id) use (&$calls) { $calls[] = $id; return "User $id"; }],
        ]]];
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('[{"userId":1,"secret":0},{"userId":2,"secret":false}]'));
        $this->atomic(fn () => $model->save(false));
        $calls = [];
        $model->setAttribute('document', RawJson::value('[{"userId":2,"secret":false},{"userId":1,"secret":0}]'));
        $this->atomic(fn () => $model->save(false));
        $change = $this->values(1)['document']['changes'];
        self::assertCount(1, $change);
        self::assertSame([], $change[0]['path']);
        self::assertSame([['userId' => 1], ['userId' => 2]], $change[0]['old']['value']);
        self::assertSame([['userId' => 'User 2'], ['userId' => 'User 1']], $change[0]['new']['display_value']);
        self::assertSame([1, 2, 2, 1], $calls);
        self::assertSame(['userId' => 'User'], $change[0]['labels']);
        self::assertStringContainsString('"secret":false', $this->values(1)['document']['data']['raw']);
    }

    public function testCollectionExcludedChangesDoNotRunFunctions(): void
    {
        $calls = 0;
        ParityRecord::$config = ['attributes' => ['document' => [
            'type' => AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION, 'only' => ['userId'],
            'functions' => ['userId' => static function ($id) use (&$calls) { ++$calls; return $id; }],
        ]]];
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('[{"userId":1,"secret":"first"}]'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame(1, $calls);
        $model->setAttribute('document', RawJson::value('[{"userId":1,"secret":"second"}]'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame(1, $calls);
        self::assertCount(2, $this->rows());
        self::assertSame([], $this->values(1)['document']['changes']);
    }

    public static function operations(): iterable
    {
        yield 'insert' => ['insert'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('operations')]
    public function testNestedCallbackErrorRollsBackEveryOperation(string $operation): void
    {
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('{"userId":1}'));
        if ($operation !== 'insert') { $this->atomic(fn () => $model->save(false)); }
        $model->getBehavior('audit')->attributes = ['document' => [
            'type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY,
            'functions' => ['userId' => static function () { throw new \RuntimeException('nested failure'); }],
        ]];
        $model->setAttribute('document', RawJson::value('{"userId":2}'));
        try {
            $this->atomic(fn () => $operation === 'delete' ? $model->delete() : $model->save(false));
            self::fail('Nested callback must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('nested failure', $error->getMessage());
        }
        self::assertCount($operation === 'insert' ? 0 : 1, $this->rows());
        self::assertSame($operation === 'insert' ? null : '{"userId":1}',
            $this->db->createCommand('SELECT document::text FROM parity_fixture')->queryScalar() ?: null);
    }

    public static function actorRoles(): iterable
    {
        yield 'client' => [['actorContactId' => 9], 9, null];
        yield 'staff' => [['actorStaffId' => 8], null, 8];
        yield 'both' => [['actorContactId' => 9, 'actorStaffId' => 8], 9, 8];
        yield 'anonymous' => [[], null, null];
    }

    #[DataProvider('actorRoles')]
    public function testExplicitActorsOnAllOperations(array $config, ?int $contact, ?int $staff): void
    {
        ParityRecord::$config = $config;
        $model = $this->record();
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('title', 'Second');
        $this->atomic(fn () => $model->save(false));
        $this->atomic(fn () => $model->delete());
        self::assertSame([$contact, $contact, $contact], array_column($this->rows(), 'contact_id'));
        self::assertSame([$staff, $staff, $staff], array_column($this->rows(), 'staff_id'));
    }

    public function testJsonActorsAndLogicalHooksDoNotOpenAnotherConnection(): void
    {
        ParityRecord::$config = ['attributes' => [
            'document' => ['type' => AppLogHelper::JSON_SINGLE_COMPLEX_ARRAY, 'model' => JsonLabels::class],
        ], 'actorProvider' => static fn ($model) => ['staff_id' => 7]];
        $opens = 0;
        $pdo = $this->db->getMasterPdo();
        $pid = $this->db->createCommand('SELECT pg_backend_pid()')->queryScalar();
        $this->db->createCommand('ALTER TABLE app_log ADD COLUMN backend_pid integer DEFAULT pg_backend_pid()')->execute();
        $count = static function () use (&$opens) { ++$opens; };
        \yii\base\Event::on(Connection::class, Connection::EVENT_AFTER_OPEN, $count);
        try {
            $model = $this->record(LinkedRecord::class);
            $model->setAttribute('document', RawJson::value('{"floor":{"from":0,"to":3}}'));
            $this->atomic(fn () => $model->save(false));
            $model->setAttribute('document', RawJson::value('{"floor":{"from":0,"to":4}}'));
            $this->atomic(fn () => $model->save(false));
        } finally {
            \yii\base\Event::off(Connection::class, Connection::EVENT_AFTER_OPEN, $count);
        }
        self::assertSame(0, $opens);
        self::assertSame($pdo, $this->db->getMasterPdo());
        self::assertSame([$pid, $pid], array_column($this->rows(), 'backend_pid'));
    }

    public function testJsonTypesMissingNullEmptyAndLargeNumbers(): void
    {
        $callbackInputs = [];
        ParityRecord::$config = ['attributes' => ['document' => [
            'type' => AppLogHelper::JSON_SINGLE_COMPLEX_ARRAY,
            'functions' => ['decimal' => static function ($n) use (&$callbackInputs) { $callbackInputs[] = $n; return $n; }],
        ]]];
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('{"big":123456789012345678901234567890,"decimal":0.123456789012345678901,"obj":{},"arr":[],"flag":false,"zero":0,"nil":null}'));
        $this->atomic(fn () => $model->save(false));
        self::assertSame(['0.123456789012345678901'], $callbackInputs);
        $raw = $this->rows()[0]['new_values'];
        self::assertStringContainsString('"value":123456789012345678901234567890', $raw);
        self::assertStringContainsString('"value":0.123456789012345678901', $raw);
        $changes = $this->values(0)['document']['changes'];
        $byName = [];
        foreach ($changes as $change) { $byName[$change['path'][0]] = $change; }
        self::assertSame('{}', $byName['obj']['new']['raw']);
        self::assertSame('[]', $byName['arr']['new']['raw']);
        self::assertFalse($byName['flag']['new']['value']);
        self::assertSame(0, $byName['zero']['new']['value']);
        self::assertFalse($byName['nil']['old']['present']);
        self::assertTrue($byName['nil']['new']['present']);
        self::assertFalse($byName['nil']['new']['sql_null']);
        $model->setAttribute('document', RawJson::value('null'));
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('document', RawJson::sqlNull());
        $this->atomic(fn () => $model->save(false));
        $change = $this->values(2)['document']['changes'][0];
        self::assertFalse($change['old']['sql_null']);
        self::assertTrue($change['new']['sql_null']);
    }

    public function testJsonbMemberEqualityAndJsonLexicalOnlyChanges(): void
    {
        ParityRecord::$config = ['attributes' => [
            'document' => ['type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY],
            'binary_document' => ['type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY],
        ]];
        $model = $this->record();
        $model->setAttribute('document', RawJson::value('{"a":1,"b":2}'));
        $model->setAttribute('binary_document', RawJson::value('{"n":123456789012345678901234567890,"v":0}', true));
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('document', RawJson::value('{ "b":2, "a":1 }'));
        $model->setAttribute('binary_document', RawJson::value('{"n":123456789012345678901234567890.00,"v":1}', true));
        $this->atomic(fn () => $model->save(false));
        self::assertSame([], $this->values(1)['document']['changes']);
        self::assertSame([['v']], array_column($this->values(1)['binary_document']['changes'], 'path'));
    }

    public function testActorRolesProviderExplicitPrecedenceAndNoSession(): void
    {
        $calls = 0;
        ParityRecord::$config = ['actorProvider' => static function ($model) use (&$calls) { ++$calls; return ['staff_id' => 12]; }];
        $model = $this->record();
        $this->atomic(fn () => $model->save(false));
        self::assertSame(12, $this->rows()[0]['staff_id']);
        self::assertNull($this->rows()[0]['contact_id']);
        $model->getBehavior('audit')->actorContactId = 23;
        $model->setAttribute('title', 'Second');
        $this->atomic(fn () => $model->save(false));
        self::assertSame(23, $this->rows()[1]['contact_id']);
        self::assertNull($this->rows()[1]['staff_id']);
        $this->atomic(fn () => $model->delete());
        self::assertSame(23, $this->rows()[2]['contact_id']);
        self::assertSame(1, $calls);
    }

    public function testSoftDeleteLegacyAndTargetedRestoration(): void
    {
        ParityRecord::$config = ['attributes' => ['title'], 'deletionMarkAttribute' => 'is_deleted', 'deletionMarkValue' => true];
        $model = $this->record();
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('is_deleted', true);
        $this->atomic(fn () => $model->save(false));
        self::assertSame('delete', $this->rows()[1]['operation']);
        self::assertTrue($this->rows()[1]['is_soft_delete']);
        self::assertSame('First', $this->values(1, 'old')['title']['data']['value']);
        self::assertSame('First', $this->values(1)['title']['data']['value']);
        self::assertTrue((bool) $this->db->createCommand('SELECT is_deleted FROM parity_fixture')->queryScalar());
        $model->refresh();
        $this->atomic(fn () => $model->save(false));
        self::assertCount(2, $this->rows());
        $model->getBehavior('audit')->attributes = ['title', 'is_deleted'];
        $model->setAttribute('is_deleted', false);
        $this->atomic(fn () => $model->save(false));
        self::assertSame('update', $this->rows()[2]['operation']);
        self::assertFalse($this->rows()[2]['is_soft_delete']);
        $model->getBehavior('audit')->deletionMarkValue = null;
        $model->setAttribute('is_deleted', true);
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('is_deleted', false);
        $this->atomic(fn () => $model->save(false));
        self::assertSame(['delete', 'delete'], array_column(array_slice($this->rows(), 3), 'operation'));
        self::assertSame(1, (int) $this->db->createCommand('SELECT count(*) FROM parity_fixture')->queryScalar());
    }

    public function testLogicalAndPhysicalIdentityRemainReadable(): void
    {
        $model = $this->record(LinkedRecord::class);
        $this->atomic(fn () => $model->save(false));
        $model->setAttribute('title', 'Second');
        $this->atomic(fn () => $model->save(false));
        $this->atomic(fn () => $model->delete());
        $physical = History::read($this->db, LinkedRecord::class, $model->getPrimaryKey(true));
        $logical = History::readLogical($this->db, 'BusinessObject', ['id' => 42]);
        self::assertSame($physical, $logical);
        self::assertCount(3, $logical);
        self::assertSame(LinkedRecord::class, $logical[0]['entity_type']);
        self::assertSame('BusinessObject', $logical[0]['logical_entity_type']);
        self::assertCount(1, History::readLogical($this->db, 'BusinessObject', ['id' => 42], 1, (int) $logical[0]['id']));
    }

    public static function failingFeatures(): iterable
    {
        foreach (['functions', 'actorProvider', 'logical', 'softAudit'] as $feature) {
            yield $feature => [$feature];
        }
    }

    #[DataProvider('failingFeatures')]
    public function testRestoredFeatureFailuresRollBack(string $feature): void
    {
        if ($feature === 'actorProvider') {
            ParityRecord::$config = ['actorProvider' => static function () { throw new \RuntimeException('expected'); }];
        } elseif ($feature === 'functions') {
            ParityRecord::$config = ['attributes' => ['document' => [
                'type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY,
                'functions' => ['userId' => static function () { throw new \RuntimeException('expected'); }],
            ]]];
        } elseif ($feature === 'softAudit') {
            ParityRecord::$config = ['deletionMarkAttribute' => 'is_deleted'];
        }
        $model = $this->record($feature === 'logical' ? InvalidLinkedRecord::class : ParityRecord::class);
        $model->setAttribute('document', RawJson::value('{"userId":1}'));
        if ($feature === 'softAudit') {
            $this->atomic(fn () => $model->save(false));
            $model->setAttribute('is_deleted', true);
            $this->db->createCommand("ALTER TABLE app_log ADD CONSTRAINT reject_soft CHECK (NOT is_soft_delete) NOT VALID")->execute();
        }
        try {
            $this->atomic(fn () => $model->save(false));
            self::fail('Feature error must propagate.');
        } catch (\RuntimeException|\LogicException|\yii\db\Exception $error) {
            self::assertNotEmpty($error->getMessage());
        }
        self::assertCount($feature === 'softAudit' ? 1 : 0, $this->rows());
        self::assertSame($feature === 'softAudit' ? 1 : 0, (int) $this->db->createCommand('SELECT count(*) FROM parity_fixture')->queryScalar());
        if ($feature === 'softAudit') {
            self::assertFalse((bool) $this->db->createCommand('SELECT is_deleted FROM parity_fixture')->queryScalar());
        }
    }

    public function testAdditiveMigrationPreservesExistingRowsAndCanRollBack(): void
    {
        ob_start();
        try {
            self::assertNotFalse((new \m261117_000003_add_app_log_context(['db' => $this->db]))->down());
            $this->db->createCommand("INSERT INTO app_log (entity_type,entity_id,operation,old_values,new_values) VALUES ('Old','{\"id\":1}','insert','{}','{}')")->execute();
            self::assertNotFalse((new \m261117_000003_add_app_log_context(['db' => $this->db]))->up());
        } finally { ob_end_clean(); }
        $rows = History::read($this->db, 'Old', ['id' => 1]);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['logical_entity_id']);
        self::assertNull($rows[0]['contact_id']);
        self::assertFalse($rows[0]['is_soft_delete']);
    }

    public function testHtmlRendererEscapesWithoutDomainOrNumberCoercion(): void
    {
        $html = AppLogHelper::getSingleRecordLogAsHtml([[
            'label' => '<Name>', 'oldVal' => false, 'newRaw' => '12345678901234567890.1234567890123456789',
        ]]);
        self::assertStringContainsString('&lt;Name&gt;', $html);
        self::assertStringContainsString('false', $html);
        self::assertStringContainsString('12345678901234567890.1234567890123456789', $html);
        self::assertStringNotContainsString('<Name>', $html);
        self::assertTrue(AppLogHelper::isJson(['document' => ['type' => AppLogHelper::JSON_SINGLE_ASSOC_ARRAY]], 'document'));
        self::assertSame('LinkedRecord', AppLogHelper::getShortClassName(LinkedRecord::class));
        self::assertSame('Создана запись', AppLogHelper::getActionName(AppLogHelper::ACTION_CREATE, AppLogHelper::TYPE_SINGLE_RECORD));
        self::assertSame('Удалено', AppLogHelper::getActionListItems()[AppLogHelper::ACTION_DELETE]);
    }
}

class ParityRecord extends ActiveRecord
{
    public static Connection $db;
    public static array $config = [];
    public static function tableName(): string { return 'parity_fixture'; }
    public static function getDb(): Connection { return self::$db; }
    public function behaviors(): array { return ['audit' => ['class' => AppLogBehavior::class] + self::$config]; }
}

final class LinkedRecord extends ParityRecord
{
    public function getUpdatedModelClass(): string { return 'BusinessObject'; }
    public function getUpdatedModelId(): int { return 42; }
}

final class InvalidLinkedRecord extends ParityRecord
{
    public function getUpdatedModelClass(): string { return ''; }
}

final class JsonLabels extends Model
{
    public function attributeLabels(): array
    {
        return ['userId' => 'User', 'secret' => 'Secret', 'floor' => 'Floor', 'from' => 'From', 'to' => 'To'];
    }
}
