# Yii2 App Log

PostgreSQL audit history for selected Yii2 ActiveRecord models. PHP 8.1+, Yii 2.0.53+ (2.0.x), PDO PostgreSQL. MIT.

## Install

The package is distributed through GitHub VCS; it is not published on Packagist.

```sh
composer config repositories.yii2-app-log vcs https://github.com/Ktulchu/yii2-app-log
composer require ktulchu/yii2-app-log:^0.1
php yii migrate --migrationPath=@vendor/ktulchu/yii2-app-log/migrations --interactive=0
```

Use your normal Yii asset repository configuration. A console-only application can require
`yidas/yii2-composer-bower-skip:^2.0` instead of installing browser assets. Allow Yii's standard
`yiisoft/yii2-composer` plugin in the application's Composer configuration. No install script runs migrations.
For a nondefault connection use Yii's `--db=auditDb` migration option, pointing at the same component
returned by the audited models' `getDb()`.

## Attach

```php
public function behaviors(): array
{
    return [
        'audit' => [
            'class' => \Ktulchu\Yii2AppLog\AppLogBehavior::class,
            'except' => ['updated_at', 'password_hash'],
        ],
    ];
}
```

Only models with this behavior are audited. `attributes` optionally supplies an inclusion list of column names;
an empty list means all columns. `except` always wins. Unknown names fail before the business SQL.
No model callbacks or nested JSON projections are evaluated.

## Transaction contract

**The application must start a transaction on the model's own connection before every audited save/delete,
and roll back on every Throwable. Never catch an audit failure and then commit.**

```php
$db = $model::getDb(); // supports custom getDb(); do not assume Yii::$app->db
$transaction = $db->beginTransaction();
try {
    if (!$model->save(false)) {
        throw new \RuntimeException('Model save was cancelled.');
    }
    $transaction->commit();
} catch (\Throwable $e) {
    if ($transaction->isActive) {
        $transaction->rollBack();
    }
    throw $e;
}
```

Use the same pattern for `delete()`, checking its return value. Validation failure and cancelled events
remain Yii's normal `false` results. Discard/reload model instances after rollback: Yii does not undo
their in-memory values, generated identifiers, or old-attribute state.

`beforeSave`/`beforeDelete` events check the transaction **before model SQL**.
The behavior never starts, commits or rolls back a transaction. It locks the old row with `FOR UPDATE`,
reads the actual stored row after the write, and inserts history using direct SQL on that same
`Connection` and physical PDO connection. Audit exceptions propagate. PostgreSQL insertion failures abort
the transaction; application rollback also undoes the business change. Do not commit/rollback/close or
replace the connection inside lifecycle handlers. The behavior verifies transaction identity, depth and
PDO identity again after the write.

If a model overrides `transactions()`, Yii starts an internal transaction/savepoint before `beforeSave`.
For these operations the behavior requires depth >= 2, proving an outer transaction exists in the
standard Yii lifecycle. An internal Yii transaction alone is rejected. An outer transaction with Yii's
normal nested savepoint is supported; this package does not release that savepoint itself.

This is a cooperative ActiveRecord event contract, not database-enforced auditing. Overrides must call
parent lifecycle methods and handlers must not stop event propagation, manipulate transactions, issue
untracked business writes, or modify the row after the audit snapshot. Recursive writes from model
lifecycle handlers and reentry from audit SQL callbacks are rejected. Prefer separate, sequential saves
in the application's transaction.

## JSON / JSONB without precision loss

Yii normally decodes both SQL NULL and JSON null to PHP null, and JSON objects to arrays.
Audit snapshots bypass that hydration and are constructed by PostgreSQL.
For writes requiring exact representation, use explicit values:

```php
use Ktulchu\Yii2AppLog\RawJson;

$model->document = RawJson::value('{ "n":123456789012345678901234567890, "empty":{} }');
$model->settings = RawJson::value('{"a":1,"b":2}', binary: true);
$model->document = RawJson::value('null'); // JSON null
$model->document = RawJson::sqlNull();    // SQL NULL, also forces Yii to mark it dirty
```

PostgreSQL validates the raw document. No intermediate PHP decoding, float conversion, or double
serialization occurs. Ordinary PHP arrays may still be used with Yii's normal JSON serialization, but
information lost by the application before the SQL write cannot be recovered by auditing.

* JSON compares exact stored text: whitespace, object order, duplicate keys and escaping matter.
* JSONB compares through PostgreSQL `IS DISTINCT FROM`: object order and equivalent numeric
  representations are equal; array order matters.
* SQL NULL and JSON null remain distinct. Empty objects/arrays, false, zero, empty strings,
  nested values and arbitrarily large JSON numbers remain intact in history.
* Each JSON column is audited as a whole value. There is no recursive JSON diff.

## Read history and schema

```php
use Ktulchu\Yii2AppLog\History;

$rows = History::read($model::getDb(), $model::class, $model->getPrimaryKey(true), limit: 100);
// Pagination: pass the last returned id as afterId.
// old_values/new_values are raw JSON strings, not automatically decoded arrays.
$values = json_decode($rows[0]['new_values'], true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
```

Decoding fractional JSON numbers into PHP may still lose precision: use `data.raw` or a
precision-preserving JSON parser when exact numbers are needed. History storage never decodes them.

The `{{%app_log}}` table respects Yii's table prefix:
`id bigint identity`, `entity_type text` (PHP model class), `entity_id jsonb` (primary-key object),
`operation text` (insert/update/delete), `occurred_at timestamptz`, `old_values json`, `new_values json`.
Indexes cover entity type/key/id and time. Composite, string, UUID and integer primary keys work;
changing a primary key records the new key for that update. Earlier history stays under the earlier key.
The timestamp is the audit insertion time, not the outer transaction's commit time.

The document shape matches `ktulchu/symfony-app-log`:

```json
{"document":{"db_type":"json","data":{"sql_null":false,"value":{},"raw":"{}"}}}
```

INSERT has an empty old document and DELETE an empty new document. Both include all selected fields,
including scalar empty values. UPDATE includes only changed fields. Repeated/no-op saves add no row.
Ordinary scalar update comparison preserves the source mechanism's PHP typecast/loose-comparison
semantics (including its null/empty equivalences); JSON uses the stricter rules above.
Models without a primary key, audit-table self-auditing, replicas/master pools and non-PostgreSQL
connections are rejected. No audit ActiveRecord model is saved, so there is no recursive audit of history.

## Independent example

`fixtures/basic` is a separate Composer console application with no application framework code copied
from another project. It installs this package through VCS by default:

```sh
cd fixtures/basic
composer install
# Set APPLOG_TEST_DSN, APPLOG_TEST_USER and APPLOG_TEST_PASSWORD to a disposable database.
php yii migrate --interactive=0
php yii smoke
```

For local development replace its package repository with a path repository pointing to `../..`.
CI performs a separate Composer install with a copied path package, applies migrations and checks CRUD.

## Tests

Use a **disposable PostgreSQL database only**: PHPUnit recreates its fixture and audit tables.

```sh
composer install
export APPLOG_TEST_DSN='pgsql:host=127.0.0.1;dbname=yii2_audit'
export APPLOG_TEST_USER=audit
export APPLOG_TEST_PASSWORD=audit
composer test
composer analyse
composer validate --strict
composer audit
```

PHPUnit covers CRUD, no-op/repeated saves, exact JSON, PostgreSQL JSONB equality, nulls and empty values,
large numbers, nesting, exclusions, partial writes, key changes, transaction fail-fast, failed audit
insertion, caller rollback, custom connections, connection identity, cancellation, recursion and
self-audit prevention. Fail-fast tests use a nontransactional sequence to detect even temporary SQL
writes. Missing test DB configuration fails rather than silently skipping. GitHub Actions runs PHP
8.1, 8.3 and 8.4 with PostgreSQL 17 and an independent Composer application.

## Limits and migration from an existing audit behavior

Version 0.1 supports ordinary single-table ActiveRecord lifecycle writes. Bulk `updateAll/deleteAll`,
`updateAttributes/updateCounters`, direct SQL, DB cascades and changes by deferred triggers are not
audited. Immediate trigger changes to the owner row are included in the post-write snapshot.
History is not an automatic record of other rows changed by triggers. Concurrency uses PostgreSQL row
locks; applications must handle deadlock/serialization retries by repeating the whole transaction with
fresh model instances.

The source mechanism's numeric `attributes` list keeps its name and selection meaning. Default selection
is deliberately easier: all fields unless excluded. Legacy labels, value callbacks, preFilter,
element-level JSON only/except/functions, implicit actor IDs, object routing, soft-delete interpretation,
localized operation strings and model identity callbacks are omitted. Associative legacy attribute
configuration fails explicitly. Soft delete is a normal UPDATE. There is no implicit user lookup,
retention policy, encryption, historical-data importer or automatic migration of an existing audit table.
Existing application-specific log tables are not altered.

Underlying contracts: [Yii ActiveRecord lifecycle and transactions](https://www.yiiframework.com/doc/api/2.0/yii-db-activerecord)
and [PostgreSQL JSON semantics](https://www.postgresql.org/docs/17/datatype-json.html).
