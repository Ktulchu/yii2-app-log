# Functional parity audit

Baseline: main at d0e1bb5. The source checkout was read only, including its current corrected
AppLogBehavior, parent HistoryBehavior, AppLogHelper, ArrayHelper, History model and actual
behaviors() configurations (field lists, value callbacks and deletionMarkAttribute).
No source application code or configuration is distributed in this package.

| Source capability | At d0e1bb5 | Change in this PR |
|---|---|---|
| INSERT / UPDATE / DELETE lifecycle events | Supported | Preserved, same PostgreSQL Connection/PDO |
| Numeric attributes list / callback shorthand / label,value,preFilter | Supported | Preserved; raw and display values remain separate |
| Scalar typecast / loose UPDATE comparison | Supported | Preserved |
| Empty values on INSERT/DELETE | Source recursively removes falsy values; package preserves them | Deliberately preserve null, false, zero, empty structures |
| JSON/JSONB whole exact snapshots | Supported | Preserved as authoritative data |
| Four declared JSON format constants | Missing | Public AppLogHelper constants restored |
| Nested object presentation, only/except/functions/model | Missing | Additional changed-member presentation, never replaces snapshots |
| Associative object collection presentation | Missing | Whole-collection replacement, selected fields and callbacks per record |
| Collection matching/order algorithm | Not present in source | No matching invented; whole replacement explicitly selected by user |
| Explicit actorContactId/actorStaffId | Missing | Nullable explicit roles and optional application actorProvider |
| Implicit session identity | Source-specific | No implicit session lookup; applications can supply a provider |
| deletionMarkAttribute | Missing | Legacy any-actual-change semantics; optional deletionMarkValue for targeted deletion |
| getUpdatedModelClass/getUpdatedModelId | Missing | Logical entity columns and reader; physical model identity retained |
| History storage and reading | Typed per-operation rows rather than source per-attribute strings | Existing reader unchanged; additive nullable context columns/migration |
| Human-readable HTML helper | Source combines generic rendering and domain status/number formatting | Escaped renderer restored; no domain status method or lossy numeric formatting |
| Helper action/type constants and name utilities | Missing | Compatibility constants/name utilities restored; storage operation strings unchanged |
| Object-specific routing, system staff defaults, UI/user classes | Intentionally missing | Remain application responsibilities |
| Swallowed audit exceptions / global audit connection | Removed | Remain forbidden; rollback contract unchanged |

## Source evidence and conflicts

The source parent calls evaluateJsonAttribute(), but does not define it. A repository-wide search and
the available Git history contain no definition. AppLogHelper defines four format names, isActive()
(empty only means all; except wins), and isUnderConstruction() always returns true. The corrected
source AppLogBehavior explicitly rejects nested JSON projections. Active configurations inspected
use scalar lists/callbacks and the soft-delete marker; no working collection matcher was found.
Consequently a claim of restored historical collection matching would be unsupported.

The user explicitly selected **whole collection replacement without record matching**. Collection
ordering remains significant; no primary key, set matching, sorting or inferred identity is introduced.
Nested objects have changed-member presentation. Nested arrays are ordered atomic values.

The source parent calls an update a delete whenever deletionMarkAttribute appears in changedAttributes,
regardless of direction or final value. Its corrected child emits a snapshot of the current row.
The package preserves any actual marker change as the default classification, including restoration,
but retains both real before/after values and marks is_soft_delete. Set deletionMarkValue for
one-direction deletion semantics. Unchanged marker assignments are never a deletion.

The source parent honors explicit actor IDs and otherwise infers a role from an application user class.
Its child overrides saveLog() with separate app/session rules, ignoring those explicit properties.
The package consistently honors explicit IDs for all operations, otherwise invokes an optional provider;
it never accesses a session or user component.

ArrayHelper's empty filtering and numeric/string coercion would erase false/0/null or round numbers.
They are not used in authoritative snapshots or JSON presentation. Scalar callbacks keep their existing
package contract; nested JSON callbacks receive exact numeric strings when PHP cannot represent a number
as an integer. SQL NULL, JSON null and missing members are distinct.

The source History model targets an application table, casts values to strings, has application-specific
foreign keys and timestamp behavior. Copying it would lose types and reintroduce dependencies. The
existing package History reader is kept, with a separate logical-entity lookup and additive migration.
