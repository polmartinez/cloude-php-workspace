# The Model IS the schema definition

> Part of the [AGENTS](../../AGENTS.md) reference. Covers the
> declarative properties every `Cloude\Model\Model` subclass uses
> as the single source of truth for the table's shape.

For projects using `Cloude\Model`, **the subclass is the single source
of truth for the table's shape**. Every other layer (cast logic,
mass-assignment guard, DDL emission, query builder) reads from these
declarations — they live next to the data, not in scattered
configuration.

```php
class User extends \Cloude\Model\Model
{
    protected static string $table       = 'users';
    protected static string $primaryKey  = 'id';

    // 1. Fields the model accepts (mass-assignment whitelist).
    protected static array $properties = [
        'id', 'email', 'name', 'role_id', 'active', 'created_at', 'tags', 'status',
    ];

    // 2. PHP-side types. Coerced on read AND write; NULL passes through.
    protected static array $types = [
        'id'         => 'int',
        'active'     => 'bool',
        'tags'       => 'json',
        'created_at' => 'datetime',
        'status'     => 'enum:' . Status::class,
    ];

    // 3. Indexes. Declarative. Emitted by indexesSql(); applied by your runner.
    protected static array $indexes = [
        ['type' => 'unique', 'columns' => ['email']],
        ['type' => 'index',  'columns' => ['role_id']],
    ];

    // 4. Foreign keys (with optional ON DELETE / ON UPDATE).
    protected static array $foreignKeys = [
        ['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'],
         'on_delete' => 'set null', 'on_update' => 'cascade'],
    ];
}
```

## What each declaration does

| Property       | Used by                                          | Effect                                                                                  |
|----------------|--------------------------------------------------|-----------------------------------------------------------------------------------------|
| `$table`       | every storage call                                | the table / collection identifier                                                       |
| `$primaryKey`  | `find()` / `save()` / `delete()`                  | by-PK lookups; protected from `save()`-time overwrite                                   |
| `$properties`  | `__set()` / `fill()` / `create()`                 | mass-assignment whitelist (untrusted-input hardening). Empty = disabled                 |
| `$types`       | `hydrate()` / `save()` / `refresh()` / `toArray(serialize: true)` | per-attribute `Cast::read` / `Cast::write` — **NULL is never coerced**             |
| `$indexes`     | `indexesSql()`                                    | `list<string>` of `CREATE [UNIQUE] INDEX` statements                                    |
| `$foreignKeys` | `foreignKeysSql()`                                | `list<string>` of `ALTER TABLE ... ADD CONSTRAINT` statements                            |

## Indexes / FKs: declared here, applied elsewhere

**Metadata-only.** The framework **emits** the SQL via `indexesSql()`
/ `foreignKeysSql()`; it doesn't **apply** it, and it doesn't use the
declarations for anything else at runtime — no cascade-from-PHP, no
eager loading, no referential validation in code. Referential
integrity comes from the database, not from PHP. The model just
declares the contract; a separate script (your installer, a phinx
task, a one-off shell) feeds the emitted strings to `pdo->exec()`:

```php
foreach (User::indexesSql()     as $sql) $pdo->exec($sql);
foreach (User::foreignKeysSql() as $sql) $pdo->exec($sql);
```

Column types in SQL (e.g. `VARCHAR(255)`, `BIGINT UNSIGNED`) live in
your migrations, not in the model. `$types` describes PHP-side
coercion, not DDL.

## Validation

Every `$indexes` / `$foreignKeys` entry is validated when the emitter
runs (see [`Cloude\Storage\Schema`](../../src/Storage/Schema.php)).
Unknown keys (typos like `'on_dlete'`), wrong types (e.g. `null` as a
string), or empty required fields throw `\InvalidArgumentException`
with the offending table / key named in the message.

`on_delete` and `on_update` **are always emitted in the SQL** —
defaulting to `NO ACTION` when not declared, so the generated
`ALTER TABLE` carries explicit referential semantics every time.
