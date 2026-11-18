# Yii2 App Log

PostgreSQL audit history for selected Yii2 ActiveRecord models. PHP 8.1+, Yii 2.0.53+ (2.0.x), PDO PostgreSQL. MIT.

## Project background

The underlying audit behavior was developed and used in a private commercial Yii2 application over several years. This public repository began when that experience was extracted, generalized, and hardened into an independent Composer package. The original development history remains in a private commercial GitLab repository; the public GitHub history covers the standalone package, not the full lifetime of the original solution. The standalone releases have their own tests and release history.

## Install

The package is distributed through GitHub VCS; it is not published on Packagist.
This document includes changes under review on `codex/restore-behavior-parity`.
They are not part of the existing v0.1.0 tag. For review install
`ktulchu/yii2-app-log:dev-codex/restore-behavior-parity` through the same VCS repository.

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

Only models with this behavior are audited. `attributes` optionally selects columns; an empty array means
all columns. It accepts a list, per-column options, scalar callbacks, or a mixture. `except` always wins.
Unknown columns, unsupported options and invalid callbacks fail before the business SQL.

```php
'attributes' => [
    'title',
    'depart_id' => [
        'label' => 'Подразделение',
        'value' => function ($id) { return Department::findOne($id)->name ?? null; },
        'preFilter' => function ($model) { return !$model->getIsArchived(); },
    ],
    // Shorthand: 'depart_id' => function ($id) { return Department::findOne($id)->name ?? null; },
],
```

`preFilter($model)` runs once per candidate field after the business write and change comparison.
A falsy result omits that field from both sides of the history entry. It does not cancel the business
write. If every candidate is filtered out, no history row is inserted. The model is the current owner,
including its delete-event state. Unchanged and excluded fields do not invoke callbacks.

`value($value)` receives the stored scalar value, converted using Yii's column PHP typecast, for each
present side: new on INSERT, old/new on UPDATE, old on DELETE. SQL NULL is passed as PHP null.
Its JSON-serializable result is saved as `display_value`; configured fields also get `label`
(the configured label or the model's attribute label). These presentation fields sit alongside
`db_type` and `data`. The original `data.value`, `data.raw` and change detection are unaffected.
The result is frozen when writing history, so later department renames do not rewrite old entries.
For example:

```json
{"depart_id":{"db_type":"integer","data":{"sql_null":false,"value":10,"raw":"10"},"label":"Подразделение","display_value":"Продажи"}}
```

Callbacks must be read-only, use the existing connection for database lookups, and must not save models,
alter the owner or manage transactions. Callback exceptions and JSON serialization errors propagate:
the caller must roll back the business operation, as with an audit SQL error.
JSON/JSONB support `label` and `preFilter`; `value` callbacks are restricted to scalar columns
to preserve lossless JSON semantics. Use the JSON presentation options below for named-member callbacks.

## Complete configuration and JSON presentation

```php
use Ktulchu\Yii2AppLog\AppLogBehavior;
use Ktulchu\Yii2AppLog\AppLogHelper;

public function behaviors(): array
{
    return ['audit' => [
        'class' => AppLogBehavior::class,
        'attributes' => [
            'name',
            'user_id' => fn ($id) => User::findOne($id)?->name,
            'depart_id' => [
                'label' => 'Подразделение',
                'value' => fn ($id) => Department::findOne($id)?->name,
                'preFilter' => fn ($model) => !$model->getIsArchived(),
            ],
            'flat' => [
                'type' => AppLogHelper::JSON_SINGLE_COMPLEX_ARRAY,
                'model' => Flat::class,
            ],
            'participants' => [
                'type' => AppLogHelper::JSON_ASSOC_ARRAYS_COLLECTION,
                'model' => Participant::class,
                'only' => ['userId'],
                'except' => [],
                'functions' => ['userId' => fn ($id) => User::findOne($id)?->name],
            ],
        ],
        'except' => ['password_hash'],
        'deletionMarkAttribute' => 'is_deleted',
        'deletionMarkValue' => true,
        // Optional; without explicit IDs/provider both roles remain null, including in CLI.
        'actorProvider' => fn ($model) => ['staff_id' => 7],
    ]];
}
```

The related model classes belong to the application. There is no mandatory user/session component,
controller or service. All configured column names, including exclusions, must exist in the schema.

Four source format names are accepted:

| Type | Additional presentation |
|---|---|
| `JSON_SINGLE_ASSOC_ARRAY` | Changed object fields, by field name |
| `JSON_SINGLE_COMPLEX_ARRAY` | Changed named object members, descending into nested objects |
| `JSON_ASSOC_ARRAYS_COLLECTION` | Whole ordered collection replacement; no record matching |
| `JSON_SINGLE_SIMPLE_ARRAY` | Whole ordered array replacement; no positional comparison |

`only` and `except` select top-level object members, or members of each collection record.
Empty `only` means all; `except` wins. For a simple array these names are stringified indexes used
only for projection, never for matching old/new elements. `functions` maps those selected member
names to callbacks. `model` is a Yii Model class whose attribute labels name the members.
Callbacks are not invoked for excluded/unchanged object members or when a collection's selected
projection is unchanged. When a collection projection changes, callbacks render both complete
selected sides; unchanged records are not separately identified.

The source provides these constants and filters, but its `evaluateJsonAttribute()` implementation
and collection matching algorithm are absent. **Historical JSON matching parity is not claimed.**
Whole collection replacement was explicitly selected to avoid inventing identity or positional rules.
Nested arrays are also atomic ordered values. JSONB object key order is PostgreSQL's order.

Configured JSON fields keep their full, authoritative `data` envelope. The additional `changes` list
is stored on the new side for INSERT/UPDATE/logical DELETE, and on the old side for physical DELETE.
Each entry has `path`, `label`, `old` and `new`; a side has `present`, `sql_null`, typed `value`,
exact `raw` and an optional frozen `display_value`. Collection sides contain selected projections;
the full original collection remains in `data`. Missing members are distinct from JSON null and SQL NULL.
Object labels come from `model->getAttributeLabel()`; collection labels also include its selected label map.
An empty changes list can coexist with a changed raw snapshot (e.g. JSON whitespace/order changes,
or changes only to fields excluded from presentation).

`only`, `except` and `functions` **never change snapshots, column change detection or the decision to
record an actual column change**. They are presentation options, not a way to redact stored raw data.
JSON member callbacks receive ordinary strings/booleans/null, in-range integers as PHP int, and all other
numbers as exact numeric strings. Objects become stdClass, arrays remain arrays; this conversion never
uses PHP float. Callback results must be JSON-serializable. Shadowed duplicate object keys use their
last occurrence in presentation; all occurrences remain in the authoritative JSON snapshot.
Presentation requires extra PostgreSQL queries proportional to the object members being inspected.

## Actors, soft deletion and logical identity

Set `actorContactId` and/or `actorStaffId` directly in behavior configuration or per request:

```php
$model->getBehavior('audit')->actorContactId = $authenticatedContactId;
```

If either explicit property is non-null, that pair takes precedence over `actorProvider`.
Nonpositive IDs normalize to null. Otherwise the optional `actorProvider($model)` returns
`['contact_id' => ?int, 'staff_id' => ?int]` (either key may be omitted).
The provider runs once for a history row and never for a no-op. Its exceptions propagate.
Providers work identically in Web, REST, CLI and jobs; any session lookup must be written explicitly
by the application. Both roles are persisted separately on INSERT/UPDATE/DELETE.

### Source of automatic changes

The nullable `source_type` column records **where** the write originated, separately from
`contact_id` and `staff_id`; no system-user account is required.
Console writes use `console:<requestedRoute>` automatically (e.g. `console:orders/sync`);
without a requested route, the fallback is `console`.
Web/REST writes without an explicit source remain `NULL`.

For a queue handler, set the source on **each affected model** to identify the actual job
instead of just the console route (e.g. `console:queue/listen`):

```php
$model->getBehavior('audit')->sourceType = 'queue:ReconcilePaymentJob';
```

You can also set `sourceType` in `behaviors()`. Explicit values override the console default;
they must contain 1..255 bytes. `NULL` selects automatic detection. The value is persisted on
INSERT/UPDATE/DELETE (including soft delete), but no-op writes produce no history.
Apply additive migration `m261118_000004_add_app_log_source_type` before using this version;
existing rows retain `NULL` in the new column.

`deletionMarkAttribute` observes an actual stored change even if the marker is outside the audit field list.
For compatibility, default `deletionMarkValue = null` means **any actual marker change**, including restoring
it, is classified as `operation = delete`, `is_soft_delete = true`. This is the source's ambiguous rule.
Set `deletionMarkValue = true` for a boolean marker, or a specific scalar status, to classify only a change
to that value as logical deletion. Other changes, including restoration, remain UPDATE.
No-op assignments are ignored; no physical delete or business-model write is performed by the behavior.
Logical deletion stores full selected **actual before and after** snapshots. Physical deletion still has
an empty new document. Consequently a restore to an excluded marker alone can have no history row,
as with any update affecting only excluded fields.

Models may implement the source hooks `getUpdatedModelClass(): string` and `getUpdatedModelId()`
to group history under a business object. IDs may be int/string or a nonempty primary-key map;
scalar IDs are stored as `{"id":...}`. Without hooks logical identity is the physical model identity.
`entity_type/entity_id` always retain the actual changed ActiveRecord class and key.
Logical identity is stored separately in `logical_entity_type/logical_entity_id`:

```php
$rows = History::readLogical($db, BusinessObject::class, ['id' => $businessId]);
```

Both readers support `limit` and `afterId`. Old rows without logical metadata remain accessible
through `History::read()`; their logical grouping/author cannot be inferred retrospectively.

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
* Each JSON column is audited as a whole value. Optional member detail is a separate presentation.

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
An additive migration adds nullable `contact_id/staff_id bigint`,
`logical_entity_type text/logical_entity_id jsonb`, `is_soft_delete boolean DEFAULT false`,
and a logical-identity history index. A separate additive migration adds nullable
`source_type varchar(255)`. Existing columns and old records are preserved.
Indexes cover entity type/key/id and time. Composite, string, UUID and integer primary keys work;
changing a primary key records the new key for that update. Earlier history stays under the earlier key.
The timestamp is the audit insertion time, not the outer transaction's commit time.

The document shape matches `ktulchu/symfony-app-log`:

```json
{"document":{"db_type":"json","data":{"sql_null":false,"value":{},"raw":"{}"}}}
```

INSERT has an empty old document and physical DELETE an empty new document. Both include all selected fields,
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

For local development use a clean package checkout/archive as a path repository and put the example
outside that package directory. CI performs a separate Composer install from a clean archive,
applies both package migrations and the example migration, and checks CRUD, JSON detail, actors,
logical grouping, soft deletion and restoration.

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
is deliberately easier: all fields unless excluded. Scalar callbacks and label/value/preFilter options
are supported. Presentation is stored separately from authoritative DB values; old-side SQL NULL is
passed to callbacks as null rather than replaced with a legacy empty-string sentinel.
JSON only/except/functions apply to additional presentation; author IDs, soft deletion and logical identity
are supported as described above. Unsupported options fail explicitly. There is no implicit user lookup,
retention policy, encryption, historical-data importer or automatic migration of an existing audit table.
Existing application-specific log tables are not altered.

When upgrading this package, run the same migration command to apply
`m261117_000003_add_app_log_context` before enabling the new behavior version. No existing migration
or v0.1.0 tag is rewritten. When migrating from the source application, replace behavior/helper namespaces,
move application actor rules into a provider or explicit IDs, and keep related label/callback models
in the application. Add the required outer transaction to every audited write.
No old application log table or data is migrated automatically.

`AppLogHelper::getSingleRecordLogAsHtml()` and `renderAttributeAsTableRow()` accept legacy presentation
rows with `label/oldVal/newVal` (and optional `oldRaw/newRaw`) and escape all output.
They do not call domain status methods, truncate values, coerce numeric strings, or round large numbers.
The source's application-specific History ActiveRecord is replaced by the package's typed History reader.
The [functional parity audit](docs/functional-parity.md) records the evidence and deliberate differences.

Underlying contracts: [Yii ActiveRecord lifecycle and transactions](https://www.yiiframework.com/doc/api/2.0/yii-db-activerecord)
and [PostgreSQL JSON semantics](https://www.postgresql.org/docs/17/datatype-json.html).
