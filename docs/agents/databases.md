# Databases: MySQL, SQLite, PostgreSQL

> Part of the [AGENTS](../../AGENTS.md) reference. What an agent needs
> to know to write code that runs on any of the three, and what the
> framework already does per driver so you don't.

## The rule

**Pick the database in config, never in code.** `Query`, `PdoStorage`,
`Model` and `Transaction` read the driver from the PDO connection and
adapt. Application code is the same on all three.

```php
// app/config/storage.php
return [
    'default' => [
        'driver' => 'pdo',
        'dsn'    => 'pgsql:host=127.0.0.1;port=5432;dbname=app',   // or mysql:… / sqlite:…
        'user'   => 'app',
        'pass'   => \Cloude\Config::env('DB_PASS'),
    ],
];
```

Do **not** set `quote_char`, pass a quote character to `new Query()` /
`new PdoStorage()`, or branch on the driver name. Those exist only as
overrides.

## Several connections

Each model names its connection; the name is a key in `storage.php`.
Not declared means `'default'`, so existing models keep working.

```php
// app/config/storage.php
return [
    'default'   => ['driver' => 'pdo', 'dsn' => 'mysql:host=db;dbname=app',        'user' => 'app', 'pass' => …],
    'analytics' => ['driver' => 'pdo', 'dsn' => 'pgsql:host=warehouse;dbname=events', 'user' => 'ro',  'pass' => …],
];

class User  extends Model { protected static string $table = 'users'; }                // 'default'
class Event extends Model { protected static string $table = 'events';
                            protected static string|array $connection = 'analytics'; }
```

| Need | Call |
|---|---|
| Which connection a model uses | `Event::connectionName()` → `'analytics'` (null for file / array storage) |
| Transaction on that connection | `Event::transaction(fn () => …)` — same as `Transaction::run($fn, 'analytics')` |
| The PDO itself | `Connection::pdo('analytics')` or `Event::storage()->pdo()` |
| Raw SQL on it | `StorageException::execute(Connection::pdo('analytics'), $sql, $params)` |
| Point a model elsewhere at runtime (tests, tenants) | `Event::configure(new PdoStorage(Connection::pdo('replica'), 'events'))`; undo with `Event::resetStorage()` |

Rules:

- **One PDO per name.** Models on the same name share it, and so share
  a transaction. Models on different names never do.
- **`Transaction::run($fn)` without a name covers `'default'` only.**
  Writes through a model on another connection inside it are committed
  on their own. Use `Model::transaction()` for that model's connection.
- **No cross-connection transaction or JOIN.** A JOIN is one SQL
  statement on one server; read from each connection and combine in
  PHP.
- Each connection may be a different server (MySQL here, PostgreSQL
  there); the builder adapts per connection.
- The connection is resolved on the model's first use and cached per
  class. `Model::resetStorage()` (one class) or `Model::resetStorage()`
  called on `Model` itself (every class) forgets it — needed after
  changing config at runtime, and between tests that reconfigure.
- A name missing from `storage.php` fails on first use with
  `"Could not auto-resolve storage for App\Event (connection 'analytics', …)"`.
- `Query::cache()` keys include the connection name, so the same SQL on
  two connections never shares a cache entry.

## What the framework handles for you

| Concern | MySQL / SQLite | PostgreSQL | You write |
|---|---|---|---|
| Identifier quoting | `` `users`.`email` `` | `"users"."email"` | `'users.email'` — bare names, never quoted by hand |
| Key of an inserted row | `lastInsertId()` | `INSERT … RETURNING` | `$id = $q->insert($data)` / `User::create($data)->id` |
| Key column other than `id` (builder only) | ignored | `RETURNING "uuid"` | `$q->insert($data, returning: 'uuid')` |
| `update()` / `delete()` with `orderBy()` + `limit()` | native | rewritten to `WHERE ctid IN (SELECT ctid … LIMIT n)` | `$q->where(…)->orderBy('id')->limit(100)->delete()` |
| `true` / `false` as a bound value | boolean | sent as `1` / `0` | `->where('active', true)` — works on `BOOLEAN` and integer columns |
| Insert-or-update in one statement | `ON DUPLICATE KEY UPDATE` (MySQL) / `ON CONFLICT … DO UPDATE` (SQLite) | `ON CONFLICT … DO UPDATE` | `$q->upsert($row, ['plan_id', 'week_no'])` / `Target::upsert($match, $values)` |
| `count()` after `groupBy()` | number of groups | number of groups | `$q->groupBy('country')->count()` |
| Nested transactions | SAVEPOINT | SAVEPOINT | `Transaction::run(fn () => …)` |
| Errors | typed | typed | `catch (DuplicateKeyException $e)` etc. — same classes on every driver |

What a model reads back differs slightly by driver (PostgreSQL returns
real booleans and integers, re-spaces `jsonb`; MySQL may return
strings). Declare `$types` on the model and the values are the same
PHP types everywhere; dirty tracking compares after the casts, so none
of this causes spurious writes.

## What you must keep portable yourself

The framework passes these through as written:

- **`selectRaw()` / `havingRaw()` expressions.** Use standard SQL
  (`COUNT(*)`, `SUM(x)`, `COALESCE`, `LOWER`). Avoid `IFNULL`,
  `DATE_FORMAT`, `GROUP_CONCAT` (MySQL) and `::type` casts,
  `STRING_AGG` (PostgreSQL) unless the app targets one server. Never
  put backticks or double-quoted identifiers in them.
- **Case-insensitive matching.** `LIKE` ignores case on MySQL's default
  collations and does not on PostgreSQL. `'ILIKE'` / `'NOT ILIKE'` are
  accepted `where()` operators but exist only on PostgreSQL. For code
  that must run on both, compare against a column stored lowercased.
- **Column types in migrations.** Yours to write. `Schema` translates a
  few MySQL names for PostgreSQL (see below); it is not a type system.
- **Hand-written SQL** run through `StorageException::execute()`.

## Native upsert

```php
// Builder: the row, then the columns of the unique key it may collide on.
$q->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5], ['plan_id', 'week_no']);
$q->upsert($row, ['plan_id', 'week_no'], ['value']);   // on collision overwrite only `value`
$q->upsert($row, ['plan_id', 'week_no'], []);          // on collision leave the row alone

// Model: returns the row (read back after the statement).
$t = Target::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 5]);
$t = Target::upsert($match, ['value' => 5], update: ['value', 'updated_at']);
```

Rules:

- **The conflict columns must be a UNIQUE index or the primary key.**
  The database detects the collision; with no such index every call
  inserts a new row (PostgreSQL and SQLite raise an error instead).
- **One statement, atomic.** Prefer it to `updateOrCreate()` whenever
  the unique index exists; no `Transaction::run()` needed around it.
- `Query::upsert()` returns nothing. `Model::upsert()` returns the
  model, hydrated from a read by the `$match` columns.
- On a collision `Model::upsert()` overwrites the `$values` columns
  only. A column filled by `beforeSave()` (`updated_at`) is sent on
  insert but overwritten on collision only when listed in `update:`.
- **MySQL has no conflict target**: any unique key of the table
  triggers the update, whatever `$conflict` says. Tables with a single
  unique key behave identically on all three servers.
- A collision still consumes an auto-increment / identity value on
  every server. Ids will have gaps; never rely on them being dense.
- `updateOrCreate()` remains for storages with no SQL (JSON, array) and
  for matching on columns that have no unique index.
  `Model::upsert()` on such a storage falls back to read-then-write.

## DDL: `Schema` and the model emitters

Every emitter takes the dialect as its last argument (`'mysql'` is the
default; `'pgsql'` for PostgreSQL). The dialect is **not** read from
the connection here — these return strings, they don't hold a PDO.

```php
$sql = Schema::createTableSql('users', $columns, $indexes, $foreignKeys, 'pgsql');
$pdo->exec($sql);                                   // one exec, even when it is several statements

foreach (User::indexesSql('pgsql')     as $s) $pdo->exec($s);
foreach (User::foreignKeysSql('pgsql') as $s) $pdo->exec($s);
```

With `'pgsql'`, `createTableSql()`:

| Descriptor | Emits |
|---|---|
| `'auto_increment' => true` | `GENERATED BY DEFAULT AS IDENTITY` |
| `'primary' => true` | `NOT NULL … PRIMARY KEY` (an explicit `NULL` would be rejected) |
| `'unsigned' => true` | nothing (no unsigned types) |
| `'type' => 'datetime'` | `timestamp` |
| `'type' => 'tinyint(1)'` / `'mediumint'` / `'int'` | `smallint` / `integer` / `integer` |
| `'type' => 'double'` | `double precision` |
| `'type' => 'longtext'` / `'mediumtext'` / `'tinytext'` | `text` |
| `'type' => 'blob'` / `'varbinary(…)'` / … | `bytea` |
| any other type | passed through (`varchar(32)`, `numeric(15,3)`, `jsonb`, `uuid`, …) |
| `['type' => 'unique', …]` index | `CONSTRAINT "uq_…" UNIQUE (…)` inside the CREATE |
| `['type' => 'index', …]` index | `CREATE INDEX …` appended after the CREATE, separated by `;` |
| `'comment' => '…'` | `COMMENT ON COLUMN …` appended after the CREATE |

So on PostgreSQL the returned string can hold several statements. Run
it with `$pdo->exec()` as one call; don't split it on `;` yourself.

## Testing against a real server

Unit tests use SQLite in memory (`useSqliteModel()`), which is enough
for almost everything. For behaviour that only a real PostgreSQL shows:

```bash
CLOUDE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=cloude_test' \
CLOUDE_TEST_PGSQL_USER=postgres CLOUDE_TEST_PGSQL_PASS=secret \
vendor/bin/cloude-test
```

Without `CLOUDE_TEST_PGSQL_DSN`, `tests/Storage/PgsqlIntegrationTest.php`
is reported as skipped and the run still passes. The MySQL / MariaDB
counterpart is `tests/Storage/MysqlIntegrationTest.php`, enabled by
`CLOUDE_TEST_MYSQL_DSN` / `_USER` / `_PASS`. Both create and drop only
tables prefixed `cloude_it_`.

To write a test of your own that needs a server or an extension:

```php
protected function setUp(): void
{
    $dsn = getenv('APP_TEST_PGSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('APP_TEST_PGSQL_DSN is not set');   // never fails the run
    }
    $this->pdo = new \PDO($dsn, …);
}
```

`markTestSkipped()` works from `setUp()` or from the test method.
`tearDown()` still runs after a skip — guard anything that `setUp()`
may not have created (`if (!isset($this->pdo)) return;`).

## Not supported

- SQL Server, Oracle: the builder may run, nothing is tested there.
- Schema introspection, migrations, `ALTER TABLE` diffs — use a
  migration tool; feed it the strings `Schema` emits if you like.
