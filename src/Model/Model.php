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
     * Wire this model class to a storage backend. Call once at boot —
     * typically from your routes or a small wiring file.
     */
    public static function configure(Storage $storage): void
    {
        self::$storages[static::class] = $storage;
    }

    /**
     * Returns the storage configured for this class (or its closest
     * configured ancestor). Throws if nothing is wired.
     *
     * Public on purpose: it's the canonical escape hatch for raw queries.
     * `User::storage()->pdo()->query(...)` is the documented way to drop
     * down to SQL when `findBy()` isn't enough.
     */
    public static function storage(): Storage
    {
        $class = static::class;
        if (!isset(self::$storages[$class])) {
            throw new \RuntimeException(
                "$class has no Storage configured; call $class::configure(new ...)",
            );
        }
        return self::$storages[$class];
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
    public static function query(): \Cloude\Db\Query
    {
        $storage = static::storage();
        if (!$storage instanceof \Cloude\Model\Storage\PdoStorage) {
            throw new \LogicException(
                static::class . '::query() requires a PdoStorage backend (got ' . $storage::class . ')',
            );
        }
        return $storage->query();
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
