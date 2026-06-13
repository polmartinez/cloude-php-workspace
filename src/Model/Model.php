<?php

declare(strict_types=1);

namespace Cloude\Model;

/**
 * Thin Active Record over `Cloude\Model\Storage` adapters.
 *
 * The model that consumer projects extend when they need typed-ish CRUD
 * over a relational database (or a JSON-file mock during tests). The
 * adapter behind the scenes is configurable per subclass — same API
 * whether the rows live in MySQL, SQLite or in-memory.
 *
 * ## The model IS the schema definition
 *
 * Everything the framework needs to know about a table — and most of
 * what your application needs to know — is declared as static
 * properties on the subclass. The model is the single source of truth;
 * everything else (cast logic, index/FK SQL emission, mass-assignment
 * guard, query builder) reads from these declarations.
 *
 *   class User extends \Cloude\Model\Model
 *   {
 *       protected static string $table      = 'users';
 *       protected static string $primaryKey = 'id';
 *
 *       // 1. Fields the model accepts.
 *       //
 *       //    Mass-assignment whitelist. Anything passed to fill() /
 *       //    create() / new User($data) that isn't listed here is
 *       //    rejected with InvalidArgumentException. Hardens against
 *       //    untrusted-input attacks. Leave empty to disable the check.
 *       protected static array $properties = [
 *           'id', 'email', 'name', 'role_id', 'active', 'created_at', 'tags', 'status',
 *       ];
 *
 *       // 2. PHP-side types.
 *       //
 *       //    `column => type` map. On read (storage → PHP) and write
 *       //    (PHP → storage), each listed column is coerced via
 *       //    Cloude\Model\Cast. NULL always passes through untouched
 *       //    — nullable columns stay nullable, never silently turn
 *       //    into 0 / '' / wrong defaults. Columns not listed here
 *       //    pass through as-is.
 *       //
 *       //    Catalogue (see Cloude\Model\Cast): int / float / string /
 *       //    bool / decimal[:N] / json | array / datetime[:FMT] /
 *       //    date[:FMT] / enum:FQCN.
 *       protected static array $types = [
 *           'id'         => 'int',
 *           'active'     => 'bool',
 *           'tags'       => 'json',
 *           'created_at' => 'datetime',
 *           'status'     => 'enum:' . Status::class,
 *       ];
 *
 *       // 3. Indexes.
 *       //
 *       //    Declarative. The framework doesn't apply them — it
 *       //    EMITS the SQL via `User::indexesSql()`, and a separate
 *       //    script (your migration runner, an install task, a
 *       //    one-off bootstrap) feeds the result to pdo->exec().
 *       //    Names auto-derive from the cols when omitted.
 *       protected static array $indexes = [
 *           ['type' => 'unique', 'columns' => ['email']],
 *           ['type' => 'index',  'columns' => ['role_id']],
 *       ];
 *
 *       // 4. Foreign keys (with optional ON DELETE / ON UPDATE).
 *       //
 *       //    Same idea: declared here, emitted via foreignKeysSql(),
 *       //    applied by your runner. Referential actions: cascade /
 *       //    set null / restrict / no action / set default.
 *       protected static array $foreignKeys = [
 *           [
 *               'columns'    => ['role_id'],
 *               'references' => 'roles',
 *               'on'         => ['id'],
 *               'on_delete'  => 'set null',
 *               'on_update'  => 'cascade',
 *           ],
 *       ];
 *   }
 *
 * ## Where each declaration is used
 *
 *   | Property       | Read by                                              | Effect                                                                |
 *   |----------------|------------------------------------------------------|-----------------------------------------------------------------------|
 *   | $table         | every storage operation                              | identifies the table / collection                                     |
 *   | $primaryKey    | find() / save() / delete()                           | by-PK lookups, the column save() refuses to overwrite                 |
 *   | $properties    | __set() / fill() / create()                          | mass-assignment whitelist; empty = no whitelist                       |
 *   | $types         | hydrate() / save() / refresh() / toArray(true)       | per-attribute Cast::read / Cast::write (null passes through)          |
 *   | $indexes       | indexesSql() ONLY                                    | list<string> of CREATE [UNIQUE] INDEX statements (metadata-only)      |
 *   | $foreignKeys   | foreignKeysSql() ONLY                                | list<string> of ALTER TABLE ADD CONSTRAINT statements (metadata-only) |
 *
 * Column TYPES (SQL — VARCHAR(255), INT, etc.) live in your migration
 * tooling, not here. `$types` describes PHP-side coercion, not the
 * DDL. This is intentional: the framework doesn't track schema
 * versions or generate migration files. It hands you the index / FK
 * SQL on demand and stays out of the up/down loop.
 *
 * **`$indexes` and `$foreignKeys` are metadata-only.** The framework
 * does NOT use them at runtime for cascading deletes, eager loading,
 * referential validation, or query optimisation. Their only effect
 * is the SQL string emitted by `indexesSql()` / `foreignKeysSql()`
 * — apply it yourself (install script / migration tool / shell task).
 * Referential integrity comes from the database, not from PHP.
 *
 *   User::configure(new PdoStorage($pdo, 'users'));   // once at boot
 *
 *   $u = User::find(42);
 *   $u->name = 'Ada';
 *   $u->save();
 *
 *   User::create(['email' => 'a@b.com', 'name' => 'Ada']);
 *   User::findBy(['active' => 1], limit: 10, orderBy: ['created_at' => 'DESC']);
 *
 *   // Optional bootstrap script:
 *   foreach (User::indexesSql() as $sql) { $pdo->exec($sql); }
 *   foreach (User::foreignKeysSql() as $sql) { $pdo->exec($sql); }
 *
 * ## What this class deliberately does NOT do
 *
 *   - Relations (`hasMany`, `belongsTo`). Two `findBy()` calls in your
 *     use case are clearer than declarative associations.
 *   - Migrations / schema versioning / up-down. `$indexes` /
 *     `$foreignKeys` are pure description — emission is on demand,
 *     application is on you (or your migration tool).
 *   - Column DDL. SQL types like `VARCHAR(255)` are NOT in `$types`;
 *     they live in your migrations. `$types` is PHP-side.
 *   - Observers / events. Hook into `save()` by overriding `beforeSave()`
 *     / `afterSave()` in the subclass — no event bus.
 *   - Validation. Use `Cloude\JsonSchema::validate` at the edge instead.
 *
 * If the subset feels too narrow, that's the cue to either subclass and
 * add what you need (the codebase is ~150 lines, easy to extend) or
 * reach for a real ORM. The framework is not trying to be Doctrine.
 */
abstract class Model
{
    /** @var array<string,mixed> */
    protected array $attributes = [];

    protected bool $isPersisted = false;

    /** Subclass: SQL table name (or any identifier the configured Storage uses). */
    protected static string $table = '';

    /**
     * Subclass: which storage backs this model. Two shapes:
     *
     *   (a) string — the name of a connection in `app/config/storage.php`.
     *       The Factory dispatches on its `driver` key. This is the
     *       common form when you have one central config file.
     *
     *         class User extends Model {
     *             protected static string|array $connection = 'default';
     *         }
     *
     *   (b) array — an inline config (same shape as a storage.php entry).
     *       Useful when the connection is 1:1 with this model and you
     *       don't want to fragment storage.php with one-off entries —
     *       typical for per-file JSON collections at idiosyncratic paths.
     *
     *         class PartyEsEuropa extends Model {
     *             protected static string|array $connection = [
     *                 'driver'      => 'json_collection',
     *                 'path'        => DATA_DIR . '/es/europa',
     *                 'primary_key' => 'slug',
     *             ];
     *         }
     *
     * PDO connections must use form (a) — they share the named pool in
     * `Cloude\Storage\Connection`. Inline form (b) is for file-based
     * drivers (`json`, `json_collection`, `array`).
     */
    protected static string|array $connection = 'default';

    /** Subclass: name of the single-column primary key. */
    protected static string $primaryKey = 'id';

    /**
     * Subclass: whitelist of allowed attribute names. Empty list = no
     * whitelist (any attribute is accepted). Set this to harden against
     * mass-assignment from untrusted input.
     *
     * @var list<string>
     */
    protected static array $properties = [];

    /**
     * Subclass: optional `column => type` map. The framework normalises
     * values on read (storage → PHP) and write (PHP → storage). Null
     * always passes through untouched, so nullable columns stay nullable.
     *
     * See {@see \Cloude\Model\Cast} for the type catalogue. Typical use:
     *
     *   protected static array $types = [
     *       'id'          => 'int',
     *       'price'       => 'decimal:2',
     *       'in_stock'    => 'bool',
     *       'tags'        => 'json',
     *       'created_at'  => 'datetime',
     *       'status'      => 'enum:' . Status::class,
     *   ];
     *
     * Leave empty to opt out — the model behaves exactly like before.
     *
     * @var array<string,string>
     */
    protected static array $types = [];

    /**
     * Subclass: optional UNIQUE / INDEX declarations.
     *
     * **Metadata-only.** The framework does NOT use this at runtime
     * for query optimisation, hint-injection, or anything else. Its
     * only consumer inside the framework is `indexesSql()`, which
     * emits standalone `CREATE [UNIQUE] INDEX` statements that you
     * apply manually (install script, migration runner, dev seed —
     * whatever fits the project).
     *
     * Why declare it here at all, then? Two reasons:
     *
     *   1. **Documentation next to the data** — humans and AI agents
     *      reading the model see the constraint surface in one place.
     *   2. **Single source of truth** when the app's own setup script
     *      applies its own indexes (no external migration tool, or
     *      the FW-emitted SQL feeds your migration directly).
     *
     *   protected static array $indexes = [
     *       ['type' => 'unique', 'columns' => ['email']],
     *       ['type' => 'index',  'columns' => ['role_id']],
     *       ['type' => 'unique', 'columns' => ['tenant_id', 'slug'], 'name' => 'uq_tenant_slug'],
     *   ];
     *
     * Names auto-derive (`uq_{table}_{cols}` / `idx_{table}_{cols}`)
     * when omitted.
     *
     * @var list<array<string,mixed>>
     */
    protected static array $indexes = [];

    /**
     * Subclass: optional foreign-key declarations.
     *
     * **Metadata-only.** Same contract as `$indexes` (above) — the
     * framework does NOT enforce these at runtime. There's no
     * cascade-from-PHP, no automatic eager-loading, no FK-aware
     * `delete()`. Referential integrity is enforced by the database
     * itself (assuming you've actually applied the SQL emitted by
     * `foreignKeysSql()`); the framework just declares the contract.
     *
     *   protected static array $foreignKeys = [
     *       [
     *           'columns'    => ['role_id'],
     *           'references' => 'roles',
     *           'on'         => ['id'],
     *           'on_delete'  => 'set null',
     *           'on_update'  => 'cascade',
     *       ],
     *   ];
     *
     * Referential actions: `cascade`, `set null`, `restrict`,
     * `no action`, `set default` (case-insensitive). Anything else
     * throws when `foreignKeysSql()` runs.
     *
     * **Both `on_delete` and `on_update` are always emitted in the
     * SQL**, defaulting to `NO ACTION` when not declared. The generated
     * `ALTER TABLE` carries explicit referential semantics every time —
     * no fallback to driver / engine implicit defaults.
     *
     * @var list<array<string,mixed>>
     */
    protected static array $foreignKeys = [];

    /** @var array<class-string, Storage> */
    private static array $storages = [];

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        if ($data !== []) {
            $this->fill($data);
        }
    }

    // ── configuration ──────────────────────────────────────────────────────

    /**
     * Explicitly wire this model class to a storage backend.
     *
     * Most apps don't need to call this — `storage()` auto-resolves
     * from the `static $connection` config on first use. Use it for:
     *
     *   - Tests: `User::configure(new ArrayStorage([...]))`
     *   - One-off scripts that build the storage inline
     *   - Override of the config-driven default
     *   - **Per-context swapping for partitioned data** (single class,
     *     many on-disk locations — see below)
     *
     * Two argument shapes:
     *
     *   (a) Storage instance — drop-in for any adapter you've built
     *       (`new ArrayStorage([...])`, `new PdoStorage($pdo, ...)`, ...).
     *
     *   (b) array — an inline config (same shape as a storage.php entry).
     *       Dispatched through `Cloude\Storage\Factory::makeFromConfig`.
     *       File-based drivers (`json`, `json_collection`, `array`) only —
     *       PDO needs a named pool entry.
     *
     * Pattern for partitioned data — a single Model class swapping
     * storage per request context:
     *
     *   class Party extends Model {
     *       protected static string $table = 'partidos';
     *       protected static string $primaryKey = 'slug';
     *
     *       public static function inContext(string $lang, string $ambito): void {
     *           static::configure([
     *               'driver'      => 'json_collection',
     *               'path'        => DATA_DIR . "/$lang/$ambito",
     *               'primary_key' => 'slug',
     *           ]);
     *       }
     *   }
     *
     *   Party::inContext('es', 'europa');
     *   $psoe = Party::find('psoe');
     *   Party::inContext('es', 'eeuu');
     *   $dem = Party::find('dem');
     *
     * Each call replaces the cached Storage for this class — explicit,
     * no hidden state beyond the static pool. PHP is request-scoped so
     * this is safe per request; in long-lived workers (Swoole / etc.)
     * you'd want to make sure inContext() runs before every relevant
     * find() / save().
     *
     * @param Storage|array<string,mixed> $storageOrConfig
     */
    public static function configure(Storage|array $storageOrConfig): void
    {
        $storage = is_array($storageOrConfig)
            ? \Cloude\Storage\Factory::makeFromConfig($storageOrConfig, static::$table)
            : $storageOrConfig;
        self::$storages[static::class] = $storage;
    }

    /**
     * Returns the storage for this class. Lazily resolved from config
     * via `Cloude\Storage\Factory` if no explicit `configure()` call
     * has been made — the factory dispatches on the connection's
     * `driver` key (`pdo` / `json` / `array`).
     *
     * Public on purpose: it's the canonical escape hatch for raw
     * queries. `User::storage()->pdo()->query(...)` drops down to SQL
     * when `findBy()` / `query()` aren't enough.
     */
    public static function storage(): Storage
    {
        $class = static::class;
        if (isset(self::$storages[$class])) {
            return self::$storages[$class];
        }
        try {
            $storage = is_array(static::$connection)
                ? \Cloude\Storage\Factory::makeFromConfig(static::$connection, static::$table)
                : \Cloude\Storage\Factory::make(static::$connection, static::$table);
            return self::$storages[$class] = $storage;
        } catch (\Throwable $e) {
            $where = is_array(static::$connection)
                ? '[inline ' . ((string) (static::$connection['driver'] ?? '?')) . ']'
                : "'" . static::$connection . "'";
            throw new \RuntimeException(
                "Could not auto-resolve storage for $class (connection $where, "
                . "table '" . static::$table . "'): " . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Returns a fresh `Cloude\Db\Query` builder bound to this model's
     * table. Convenience shortcut for `static::storage()->query()` —
     * only works when the configured storage is `PdoStorage`.
     *
     *   $rows = User::query()
     *       ->where('age', '>', 18)
     *       ->orderBy('name')
     *       ->get();
     *
     * Rows are plain arrays. Lift them back into Model instances with
     * `User::hydrate($row)` if you want typed objects.
     */
    public static function query(): \Cloude\Storage\Query
    {
        $storage = static::storage();
        if (!$storage instanceof \Cloude\Model\Storage\PdoStorage) {
            throw new \LogicException(
                static::class . '::query() requires a PdoStorage backend (got ' . $storage::class . ')',
            );
        }
        return $storage->query();
    }

    // ── static table / field / alias helpers ──────────────────────────────

    /**
     * The SQL table name declared by the subclass. Use it to build joins
     * and raw SQL without hard-coding string literals:
     *
     *   $sql = "SELECT * FROM " . User::table() . " WHERE active = 1";
     */
    public static function table(): string
    {
        return static::$table;
    }

    /**
     * Qualifies a column with this model's table:
     *
     *   User::field('email');    // 'users.email'
     *   User::field('*');        // 'users.*'
     *
     * The result is a plain dotted string — the Query builder quotes it
     * via `Identifier::qualify()` when it appears in SELECT / WHERE /
     * JOIN clauses, so the same call works everywhere.
     */
    public static function field(string $column): string
    {
        return static::$table . '.' . $column;
    }

    /**
     * Returns a {@see \Cloude\Storage\TableRef} aliasing this table. Pass
     * it into `Query::from()` or `Query::join()` to drive JOINs in a
     * typed way:
     *
     *   $u = User::as('u');
     *   $o = Order::as('o');
     *
     *   User::query()->from($u)
     *       ->select($u->field('*'), $o->field('total'))
     *       ->join($o, $o->field('user_id'), '=', $u->field('id'))
     *       ->where($o->field('status'), 'paid')
     *       ->get();
     */
    public static function as(string $alias): \Cloude\Storage\TableRef
    {
        return new \Cloude\Storage\TableRef(static::$table, $alias);
    }

    /**
     * Like {@see as()} but without an alias — useful when you want a
     * `TableRef` to pass around without renaming the table.
     */
    public static function ref(): \Cloude\Storage\TableRef
    {
        return new \Cloude\Storage\TableRef(static::$table);
    }

    /**
     * Standalone `CREATE [UNIQUE] INDEX` statements for every entry in
     * {@see $indexes}. Returns a list — empty when nothing's declared.
     *
     *   foreach (User::indexesSql() as $sql) {
     *       $pdo->exec($sql);
     *   }
     *
     * `$dialect`: `'mysql'` (default) or `'pgsql'`.
     *
     * @return list<string>
     */
    public static function indexesSql(string $dialect = 'mysql'): array
    {
        return array_map(
            static fn (array $idx) => \Cloude\Storage\Schema::indexSql(static::$table, $idx, $dialect),
            static::$indexes,
        );
    }

    /**
     * Standalone `ALTER TABLE ... ADD CONSTRAINT ...` statements for
     * every entry in {@see $foreignKeys}. Returns a list — empty when
     * nothing's declared.
     *
     *   foreach (User::foreignKeysSql() as $sql) {
     *       $pdo->exec($sql);
     *   }
     *
     * @return list<string>
     */
    public static function foreignKeysSql(string $dialect = 'mysql'): array
    {
        return array_map(
            static fn (array $fk) => \Cloude\Storage\Schema::foreignKeySql(static::$table, $fk, $dialect),
            static::$foreignKeys,
        );
    }

    /**
     * Build the typed `[column, alias]` pair that `Query::select()`
     * accepts. The column is qualified by this model's table:
     *
     *   User::alias('name', 'user_name');
     *   // → ['users.name', 'user_name']
     *
     *   User::query()->select('id', User::alias('name', 'user_name'))->get();
     *   // SELECT `id`, `users`.`name` AS `user_name` FROM `users`
     *
     * @return array{0:string, 1:string}
     */
    public static function alias(string $column, string $alias): array
    {
        return [static::$table . '.' . $column, $alias];
    }

    // ── static finders ─────────────────────────────────────────────────────

    public static function find(mixed $id): ?static
    {
        $row = static::storage()->find($id);
        return $row === null ? null : static::hydrate($row);
    }

    /**
     * @param  array<string,mixed>          $criteria
     * @param  array<string,'ASC'|'DESC'>|null $orderBy
     * @return list<static>
     */
    public static function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $rows = static::storage()->findBy($criteria, $limit, $offset, $orderBy);
        return array_map(static fn (array $r) => static::hydrate($r), $rows);
    }

    /**
     * @param array<string,'ASC'|'DESC'>|null $orderBy
     * @return list<static>
     */
    public static function all(?int $limit = null, ?int $offset = null, ?array $orderBy = null): array
    {
        return static::findBy([], $limit, $offset, $orderBy);
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public static function count(array $criteria = []): int
    {
        return static::storage()->count($criteria);
    }

    /**
     * Sugar: `new static($data)` + `save()`. Returns the persisted instance.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): static
    {
        return (new static($data))->save();
    }

    /**
     * "Upsert by lookup keys." Find a row whose columns match every
     * key/value pair in `$match`. If found, fill it with `$values` and
     * `save()`; if not found, create a new row from `$match + $values`.
     * Returns the persisted instance either way.
     *
     *   ProgramMetricTarget::updateOrCreate(
     *       ['plan_id' => $p, 'week_no' => $w, 'day_group_id' => $g, 'metric_id' => $m],
     *       ['target_value' => $v, 'target_unit_id' => $u],
     *   );
     *
     * The `$match` array is the "natural key" — typically the columns
     * that uniquely identify the row (and ideally back a UNIQUE index
     * in the DB). `$values` is what may change per call. When the two
     * arrays share a key, `$values` wins on the create path.
     *
     * `$values` may be `[]` — that's the read-or-create pattern
     * (effectively `firstOrCreate`): existing row returned untouched,
     * missing row created from `$match` alone.
     *
     * **Race caveat.** find + update is NOT atomic on its own. Two
     * requests racing on the same `$match` can each see "no row" and
     * both `create()`. To make this safe in production:
     *
     *   - put a UNIQUE index on the `$match` columns, AND
     *   - wrap the call in `Transaction::run(...)` (REPEATABLE READ or
     *     SERIALIZABLE) so the loser hits `DuplicateKeyException` and
     *     can retry or fall back to an UPDATE.
     *
     * @param array<string,mixed> $match
     * @param array<string,mixed> $values
     */
    public static function updateOrCreate(array $match, array $values = []): static
    {
        $rows = static::storage()->findBy($match, limit: 1);
        if ($rows !== []) {
            $instance = static::hydrate($rows[0]);
            if ($values !== []) {
                $instance->fill($values)->save();
            }
            return $instance;
        }
        return static::create(array_merge($match, $values));
    }

    // ── instance API ───────────────────────────────────────────────────────

    /**
     * Bulk-set attributes. Throws on unknown keys when `$properties` is
     * declared (whitelist mode) — handy guard against mass assignment.
     *
     * @param array<string,mixed> $data
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->__set((string) $key, $value);
        }
        return $this;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        if (static::$properties !== [] && !in_array($name, static::$properties, true)) {
            throw new \InvalidArgumentException(
                "Unknown property '$name' on " . static::class
                . ' (allowed: ' . implode(', ', static::$properties) . ')',
            );
        }
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public function id(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    public function isPersisted(): bool
    {
        return $this->isPersisted;
    }

    /**
     * Snapshot of the model's attributes. Pass `serialize: true` to apply
     * the write-side casts (DateTimeImmutable → string, BackedEnum →
     * value, json → string, …) — handy when feeding the result to
     * `Response::json`.
     *
     * @return array<string,mixed>
     */
    public function toArray(bool $serialize = false): array
    {
        if (!$serialize || static::$types === []) {
            return $this->attributes;
        }
        return self::applyTypes($this->attributes, /* write: */ true);
    }

    /**
     * INSERT (when not yet persisted) or UPDATE (when loaded from storage).
     * Returns `$this` for chaining.
     */
    public function save(): static
    {
        $this->beforeSave();

        $payload = static::$types === []
            ? $this->attributes
            : self::applyTypes($this->attributes, /* write: */ true);

        if ($this->isPersisted && $this->id() !== null) {
            unset($payload[static::$primaryKey]);                // never re-write PK
            static::storage()->update($this->id(), $payload);
        } else {
            $newId = static::storage()->insert($payload);
            if ($newId !== null && $newId !== false && $this->id() === null) {
                $this->attributes[static::$primaryKey] = $newId;
            }
            $this->isPersisted = true;
        }

        $this->afterSave();
        return $this;
    }

    public function delete(): bool
    {
        if (!$this->isPersisted || $this->id() === null) {
            return false;
        }
        $ok = static::storage()->delete($this->id());
        if ($ok) {
            $this->isPersisted = false;
        }
        return $ok;
    }

    /**
     * Re-read attributes from the storage. Useful after an INSERT when the
     * backend filled in DB-level defaults (created_at, etc.).
     */
    public function refresh(): static
    {
        if (!$this->isPersisted || $this->id() === null) {
            return $this;
        }
        $row = static::storage()->find($this->id());
        if ($row !== null) {
            $this->attributes = static::$types === []
                ? $row
                : self::applyTypes($row, /* write: */ false);
        }
        return $this;
    }

    // ── hooks (override in subclasses) ─────────────────────────────────────

    protected function beforeSave(): void {}
    protected function afterSave(): void {}

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Build a fresh instance from a row already coming from storage. Used
     * by `find` / `findBy` / `all` — bypasses `fill()` so the whitelist
     * doesn't reject DB-managed columns we forgot to declare.
     *
     * Public on purpose: when you drop down to a raw query (e.g.
     * `User::query()->where(...)->get()` returning `array<array>`), you
     * may want to lift the rows back into `User` instances. Subclasses
     * override this to add column casting / json decoding / etc.
     *
     * @param array<string,mixed> $row
     */
    public static function hydrate(array $row): static
    {
        $instance = new static();
        $instance->attributes  = static::$types === [] ? $row : self::applyTypes($row, /* write: */ false);
        $instance->isPersisted = true;
        return $instance;
    }

    /**
     * Apply the per-attribute cast map to an attribute set.
     *
     * @param  array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private static function applyTypes(array $attrs, bool $write): array
    {
        foreach (static::$types as $col => $type) {
            if (!array_key_exists($col, $attrs)) {
                continue;
            }
            $attrs[$col] = $write
                ? Cast::write($attrs[$col], $type)
                : Cast::read($attrs[$col], $type);
        }
        return $attrs;
    }

}
