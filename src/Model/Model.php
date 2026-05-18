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
 * Convention:
 *
 *   class User extends \Cloude\Model\Model
 *   {
 *       protected static string $table       = 'users';
 *       protected static string $primaryKey  = 'id';
 *       protected static array  $properties  = ['id', 'email', 'name', 'created_at'];
 *   }
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
 * What this class deliberately does NOT do:
 *   - Relations (`hasMany`, `belongsTo`). Two `findBy()` calls in your
 *     use case are clearer than declarative associations.
 *   - Observers / events. Hook into `save()` by overriding `beforeSave()`
 *     / `afterSave()` in the subclass — no event bus.
 *   - Validation. Use `Cloude\JsonSchema::validate` at the edge instead.
 *   - Casting. What comes from the storage comes; cast in accessors if
 *     you need it (`public function age(): int { return (int) $this->age; }`).
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
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * INSERT (when not yet persisted) or UPDATE (when loaded from storage).
     * Returns `$this` for chaining.
     */
    public function save(): static
    {
        $this->beforeSave();

        if ($this->isPersisted && $this->id() !== null) {
            $payload = $this->attributes;
            unset($payload[static::$primaryKey]);                // never re-write PK
            static::storage()->update($this->id(), $payload);
        } else {
            $newId = static::storage()->insert($this->attributes);
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
            $this->attributes = $row;
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
        $instance->attributes  = $row;
        $instance->isPersisted = true;
        return $instance;
    }
}
