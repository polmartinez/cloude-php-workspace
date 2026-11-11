<?php

declare(strict_types=1);

namespace Cloude\Storage;

use Cloude\Cache\Cache;

/**
 * Minimal SQL query builder. SELECT / INSERT / UPDATE / DELETE plus
 * WHERE, JOIN, ORDER BY, LIMIT, OFFSET. Works on MySQL, SQLite and
 * PostgreSQL: identifier quoting and the few dialect differences
 * (`RETURNING` on insert, `LIMIT` on UPDATE / DELETE) follow the PDO
 * driver. Pass a quote character as the third constructor arg only to
 * override that.
 *
 * Designed to cover the 80% of queries you write by hand without
 * re-implementing a full SQL grammar. **Does ship:**
 *
 *   - SELECT / INSERT / UPDATE / DELETE, native upsert (`upsert()`)
 *   - WHERE / orWhere / whereIn / whereNull / whereBetween / ...
 *   - Nested WHERE groups via `whereGroup(fn ($q) => ...)` and
 *     `orWhereGroup(...)` — for `(a = 1 OR b = 2) AND (c = 3)` style
 *   - JOIN / leftJoin / rightJoin / crossJoin, with TableRef-aware
 *     aliases (`User::as('u')`)
 *   - GROUP BY + HAVING (`groupBy(...)`, `havingRaw($expr, $bindings)`)
 *   - Raw aggregate SELECT expressions via `selectRaw('COUNT(*)', 'n')`
 *   - ORDER BY, LIMIT, OFFSET, `count()` (auto-wraps when GROUP BY is set)
 *
 * **Does not** ship: UNIONs, subqueries, window functions, CTEs,
 * SAVEPOINTs. For any of that, drop down to the PDO connection and
 * write the SQL.
 *
 * Construction:
 *
 *   $q = new Query($pdo, 'users');
 *   $q = new Query($pdo, User::as('u'));   // aliased
 *   $q = User::query();                    // via the Model
 *
 * SELECT:
 *
 *   $q->where('age', '>', 18)
 *     ->where('active', 1)
 *     ->orderBy('name')
 *     ->limit(10)
 *     ->get();                              // list<array>
 *
 *   $q->where('email', 'a@b.com')->first(); // ?array
 *   $q->where('active', 1)->count();        // int
 *   $q->select('email')->pluck('email');    // list<string>
 *
 * WHERE variants:
 *
 *   $q->where('age', '>', 18);              // explicit op
 *   $q->where('email', 'a@b.com');          // shorthand for '='
 *   $q->whereIn('id', [1, 2, 3]);
 *   $q->whereNull('deleted_at');
 *   $q->whereBetween('age', 18, 65);
 *   $q->orWhere('role', 'admin');           // OR-joined to previous
 *
 * Nested WHERE groups (for OR/AND mixed predicates):
 *
 *   $q->where('active', 1)
 *     ->whereGroup(fn ($g) =>
 *         $g->where('role', 'admin')->orWhere('role', 'editor'))
 *     ->orWhereGroup(fn ($g) =>
 *         $g->where('country', 'ES')->where('vip', 1));
 *   // WHERE active = 1
 *   //   AND (role = 'admin' OR role = 'editor')
 *   //   OR  (country = 'ES' AND vip = 1)
 *
 * JOINs:
 *
 *   $u = User::as('u');
 *   $o = Order::as('o');
 *   $q = User::query()->from($u)                 // re-anchors FROM with alias
 *       ->select($u->field('*'), $o->field('total'))
 *       ->join($o, $o->field('user_id'), '=', $u->field('id'))
 *       ->where($o->field('status'), 'paid')
 *       ->get();
 *
 *   // Shortcuts: leftJoin / rightJoin / crossJoin.
 *   // crossJoin takes no ON condition.
 *
 * Mutation (uses the current WHERE; ignores JOINs / SELECT):
 *
 *   $id    = $q->insert(['name' => 'Ada', ...]);
 *   $count = $q->where(...)->update(['active' => 0]);
 *   $count = $q->where(...)->delete();
 *
 * UPDATE/DELETE accept ORDER BY + LIMIT on every driver (PostgreSQL
 * gets an equivalent `ctid IN (SELECT ...)` form).
 *
 * Debugging:
 *
 *   echo $q->where('age', '>', 18)->compile();
 *   // → SELECT * FROM `users` WHERE `age` > 18
 */
final class Query
{
    /** @var list<string> */
    private const ALLOWED_OPS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE',
        'ILIKE', 'NOT ILIKE',           // PostgreSQL: case-insensitive LIKE
        'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'IS NULL', 'IS NOT NULL',
    ];

    /** @var list<string> */
    private array $select = ['*'];

    /** Raw SELECT expressions (aggregates, functions); appended after $select. @var list<string> */
    private array $selectRaw = [];

    private string|TableRef $table;

    /** @var list<array{type:string, table:string|TableRef, left:?string, op:?string, right:?string}> */
    private array $joins = [];

    /** @var list<WhereClause> */
    private array $wheres = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{expr: string, bindings: list<mixed>}> */
    private array $havings = [];

    /** @var list<array{col:string, dir:string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** Set by cache(); null means "do not cache this query". */
    private ?int $cacheTtl = null;
    private ?string $cacheKey = null;
    private ?string $cacheStore = null;

    private string $quoteChar;

    /** PDO driver name (`mysql`, `pgsql`, `sqlite`, …) — picks the dialect. */
    private string $driver;

    /**
     * `$quoteChar` left null picks the identifier quote from the PDO
     * driver: `"` for PostgreSQL, a backtick for MySQL / SQLite.
     */
    public function __construct(
        private \PDO $pdo,
        string|TableRef $table,
        ?string $quoteChar = null,
    ) {
        $this->table = $table;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->driver    = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->quoteChar = $quoteChar ?? Identifier::quoteCharFor($this->pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    // ── builder methods ────────────────────────────────────────────────────

    /**
     * Pick which columns to SELECT. Pass none to reset to `*`. Each
     * column may be:
     *
     *   - a plain identifier:    `'email'`
     *   - a qualified one:       `'u.email'`, `'orders.*'`
     *   - an aliased pair (**recommended**): `['name', 'type_name']`
     *     compiles to `` `name` AS `type_name` ``
     *   - a legacy `AS` string:  `'name AS type_name'` — still accepted
     *
     * The two-element-array form is preferred because it composes with
     * helpers like `Model::alias()` and `TableRef::alias()` (which both
     * emit the same shape), and because it sidesteps any whitespace /
     * keyword parsing.
     *
     * @param string|array{0:string, 1:string} ...$columns
     */
    public function select(string|array ...$columns): static
    {
        if ($columns === []) {
            $this->select = ['*'];
            return $this;
        }
        $normalised = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                if (count($col) !== 2 || !isset($col[0], $col[1])) {
                    throw new \InvalidArgumentException(
                        'Aliased column must be a two-element [column, alias] array',
                    );
                }
                $normalised[] = $col[0] . ' AS ' . $col[1];
            } else {
                $normalised[] = $col;
            }
        }
        $this->select = $normalised;
        return $this;
    }

    /**
     * Append a **raw** SQL expression to the SELECT list — emitted
     * as-is, no identifier quoting. Use for aggregates and functions:
     *
     *   $q->selectRaw('COUNT(*)', 'total')
     *     ->selectRaw('SUM(price)', 'revenue')
     *     ->groupBy('country');
     *   // SELECT COUNT(*) AS `total`, SUM(price) AS `revenue` FROM ... GROUP BY `country`
     *
     * The first `selectRaw()` clears the default `*` wildcard, so a
     * pure-aggregate SELECT just works. To mix raw aggregates with
     * regular columns, call `select(...)` for the columns first and
     * then `selectRaw(...)` for each aggregate:
     *
     *   $q->select('country')->selectRaw('COUNT(*)', 'total')->groupBy('country');
     *
     * `$expression` is **trusted** — never feed unvalidated user input
     * into it (bind values through `havingRaw($expr, [...])` or use
     * the regular `where()` API for that).
     */
    public function selectRaw(string $expression, ?string $alias = null): static
    {
        if ($this->select === ['*']) {
            $this->select = [];
        }
        $this->selectRaw[] = $alias !== null
            ? $expression . ' AS ' . $this->q($alias)
            : $expression;
        return $this;
    }

    /**
     * GROUP BY one or more columns. Identifiers are quoted (`u.role`
     * → `` `u`.`role` ``). Reorder to match SQL: WHERE → GROUP BY →
     * HAVING → ORDER BY → LIMIT.
     *
     *   $q->select('country')->selectRaw('COUNT(*)', 'n')
     *     ->groupBy('country')
     *     ->havingRaw('COUNT(*) > ?', [10])
     *     ->orderBy('n', 'DESC')
     *     ->get();
     */
    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $c) {
            $this->groups[] = $c;
        }
        return $this;
    }

    /**
     * HAVING with a raw expression and bound parameters. Necessary
     * because HAVING typically references aggregates (`COUNT(*)`,
     * `SUM(amount)`) or SELECT aliases that the builder can't model
     * as plain identifiers.
     *
     *   $q->havingRaw('COUNT(*) > ?', [10])
     *     ->havingRaw('SUM(price) BETWEEN ? AND ?', [100, 500]);
     *   // HAVING COUNT(*) > 10 AND SUM(price) BETWEEN 100 AND 500
     *
     * Multiple calls AND-join. `$expression` is emitted verbatim — DO
     * NOT interpolate user input into it; pass values through
     * `$bindings` so they're parameterized.
     */
    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->havings[] = ['expr' => $expression, 'bindings' => array_values($bindings)];
        return $this;
    }

    /**
     * Replaces the FROM target (e.g. to re-anchor with an alias after
     * `User::query()` set the bare table). Accepts a string or TableRef.
     */
    public function from(string|TableRef $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * AND-WHERE. Three call shapes:
     *
     *   where('col', '=', $value)   // explicit op
     *   where('col', $value)        // shorthand for '='
     *   where('col', 'IS NULL')     // op-only (no value)
     *
     * Column may be qualified (`'u.email'`).
     */
    public function where(string $column, mixed $opOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('AND', $column, $opOrValue, $value, func_num_args());
    }

    public function orWhere(string $column, mixed $opOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('OR', $column, $opOrValue, $value, func_num_args());
    }

    /**
     * Nested AND-group. The closure receives a fresh Query whose WHERE
     * clauses are wrapped in parentheses and AND-joined to the outer query.
     *
     *   $q->whereGroup(fn ($g) => $g->where('a', 1)->orWhere('b', 2));
     *   // → AND (a = 1 OR b = 2)
     *
     * @param callable(self): mixed $builder
     */
    public function whereGroup(callable $builder): static
    {
        return $this->addGroup('AND', $builder);
    }

    /** Like whereGroup() but OR-joined. */
    public function orWhereGroup(callable $builder): static
    {
        return $this->addGroup('OR', $builder);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = WhereClause::predicate('AND', $column, 'IN', array_values($values));
        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = WhereClause::predicate('AND', $column, 'NOT IN', array_values($values));
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = WhereClause::predicate('AND', $column, 'IS NULL', null);
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = WhereClause::predicate('AND', $column, 'IS NOT NULL', null);
        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres[] = WhereClause::predicate('AND', $column, 'BETWEEN', [$low, $high]);
        return $this;
    }

    // ── joins ─────────────────────────────────────────────────────────────

    /** INNER JOIN. Accepts string or TableRef table, qualified columns. */
    public function join(string|TableRef $table, string $left, string $op, string $right): static
    {
        return $this->addJoin('INNER', $table, $left, $op, $right);
    }

    public function leftJoin(string|TableRef $table, string $left, string $op, string $right): static
    {
        return $this->addJoin('LEFT', $table, $left, $op, $right);
    }

    public function rightJoin(string|TableRef $table, string $left, string $op, string $right): static
    {
        return $this->addJoin('RIGHT', $table, $left, $op, $right);
    }

    /** CROSS JOIN — no ON condition. */
    public function crossJoin(string|TableRef $table): static
    {
        $this->joins[] = [
            'type'  => 'CROSS',
            'table' => $table,
            'left'  => null,
            'op'    => null,
            'right' => null,
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['col' => $column, 'dir' => $direction];
        return $this;
    }

    public function limit(int $n): static
    {
        $this->limit = max(0, $n);
        return $this;
    }

    public function offset(int $n): static
    {
        $this->offset = max(0, $n);
        return $this;
    }

    // ── caching ───────────────────────────────────────────────────────────

    /**
     * Cache every subsequent SELECT terminal (`get`, `first`, `count`,
     * `value`, `pluck`) on this query for `$ttl` seconds. The cache key
     * defaults to a `sha1(connection + sql + bindings)` digest, so any change to the
     * builder state (WHEREs, ORDER BY, columns, ...) automatically gets
     * a fresh entry — no manual invalidation needed for simple TTL use.
     * The digest also covers the connection (its name in
     * `Cloude\Storage\Connection`), so two databases running the same
     * SQL keep separate entries. A PDO that is not in the pool is told
     * apart only by driver: give such queries an explicit `$key`.
     *
     *   User::query()->where('active', 1)->cache(300)->get();
     *
     * Pass `$key` for stable, app-managed keys (so you can `Cache::forget()`
     * them after a write):
     *
     *   User::query()->where('active', 1)->cache(300, 'active-users')->get();
     *
     * Pass `$store` to route to a non-default store. The store is resolved
     * lazily via `Cloude\Cache\Cache::store($store)`, so it only needs to
     * be configured when a cached query actually executes.
     *
     * Mutations (`insert`/`update`/`delete`) ignore this flag — the
     * cache layer only wraps SELECTs.
     */
    public function cache(int $ttl = 60, ?string $key = null, ?string $store = null): static
    {
        $this->cacheTtl   = max(0, $ttl);
        $this->cacheKey   = $key;
        $this->cacheStore = $store;
        return $this;
    }

    // ── execution: SELECT ─────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        [$sql, $params] = $this->buildSelect();
        return $this->remember($sql, $params, function () use ($sql, $params): array {
            $stmt = StorageException::execute($this->pdo, $sql, $params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows === false ? [] : $rows;
        });
    }

    /**
     * Returns the first matching row (after applying ORDER BY) or null.
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        $oldLimit = $this->limit;
        $this->limit = 1;
        try {
            $rows = $this->get();
        } finally {
            $this->limit = $oldLimit;
        }
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        // GROUP BY case: a plain SELECT COUNT(*) ... GROUP BY x would
        // return one row per group, not a scalar. Wrap the original
        // SELECT in a derived table and count the groups instead — the
        // useful semantics (how many distinct groups matched).
        if ($this->groups !== []) {
            // With no columns chosen, `SELECT *` under GROUP BY is
            // rejected by PostgreSQL (and MySQL's ONLY_FULL_GROUP_BY):
            // the groups are all that matters here, select a constant.
            $bare = $this->select === ['*'] && $this->selectRaw === [];
            if ($bare) {
                $this->select    = [];
                $this->selectRaw = ['1'];
            }
            try {
                [$inner, $params] = $this->buildSelect();
            } finally {
                if ($bare) {
                    $this->select    = ['*'];
                    $this->selectRaw = [];
                }
            }
            $sql = 'SELECT COUNT(*) FROM (' . $inner . ') AS cloude_grp_count';
            return $this->remember($sql, $params, function () use ($sql, $params): int {
                $stmt = StorageException::execute($this->pdo, $sql, $params);
                return (int) $stmt->fetchColumn();
            });
        }

        $oldSelect    = $this->select;
        $oldSelectRaw = $this->selectRaw;
        $this->select    = [];
        $this->selectRaw = ['COUNT(*)'];
        try {
            [$sql, $params] = $this->buildSelect();
        } finally {
            $this->select    = $oldSelect;
            $this->selectRaw = $oldSelectRaw;
        }
        return $this->remember($sql, $params, function () use ($sql, $params): int {
            $stmt = StorageException::execute($this->pdo, $sql, $params);
            return (int) $stmt->fetchColumn();
        });
    }

    /**
     * Returns the value at $column from the first matching row, or null.
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        return $row[self::lastSegment($column)] ?? null;
    }

    /**
     * Extracts a column from every matching row. Optionally re-keys the
     * result by another column.
     *
     * Column names may be qualified (`'u.email'`); the result is keyed
     * by the last segment (`'email'`).
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $cols = $key !== null ? [$column, $key] : [$column];
        $rows = $this->select(...$cols)->get();

        $valKey = self::lastSegment($column);
        if ($key === null) {
            return array_column($rows, $valKey);
        }
        $idxKey = self::lastSegment($key);
        $out = [];
        foreach ($rows as $row) {
            $out[$row[$idxKey]] = $row[$valKey];
        }
        return $out;
    }

    // ── execution: mutations ──────────────────────────────────────────────

    /**
     * INSERT one row. Returns the auto-generated primary key (or null).
     *
     * MySQL / SQLite report the key through `lastInsertId()`. PostgreSQL
     * has no such thing per statement, so the INSERT carries a
     * `RETURNING` clause there: the value of `$returning` when given,
     * otherwise of the `id` column (null when the table has none).
     * `$returning` is ignored on the other drivers.
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data, ?string $returning = null): mixed
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() requires at least one column');
        }
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->qualifyTable($this->table)
            . ' (' . implode(', ', array_map(fn (string $c) => $this->q($c), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        if ($this->driver === 'pgsql') {
            $sql .= ' RETURNING ' . ($returning !== null ? $this->q($returning) : '*');
            $row = StorageException::execute($this->pdo, $sql, array_values($data))->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? ($row[$returning ?? 'id'] ?? null) : null;
        }

        StorageException::execute($this->pdo, $sql, array_values($data));

        $last = $this->pdo->lastInsertId();
        if ($last === '' || $last === false || $last === '0') {
            return null;
        }
        return ctype_digit($last) ? (int) $last : $last;
    }

    /**
     * Native upsert: INSERT the row, or — when it collides with a
     * unique key — UPDATE the existing one, in a single atomic
     * statement. No read first, so two concurrent calls cannot both
     * insert.
     *
     *   $q->upsert(
     *       ['plan_id' => 1, 'week_no' => 2, 'target_value' => 5],   // the row
     *       ['plan_id', 'week_no'],                                   // the unique key it may collide on
     *   );
     *
     * `$conflict` must name the columns of a UNIQUE index or the
     * primary key — the database decides what a collision is, not this
     * method. `$update` lists the columns to overwrite on a collision;
     * left null it is every column of `$data` outside `$conflict`. An
     * empty list means "insert if missing, otherwise leave the row".
     *
     * Emitted as `ON CONFLICT (...) DO UPDATE SET` on PostgreSQL and
     * SQLite, `ON DUPLICATE KEY UPDATE` on MySQL / MariaDB. MySQL has
     * no conflict target: there ANY unique key of the table triggers
     * the update, so keep `$conflict` equal to the table's only unique
     * key if the code must behave the same everywhere.
     *
     * Returns nothing: what a driver reports for an upsert (affected
     * rows, generated key) is not comparable across servers. Read the
     * row back by its key when you need it, or use `Model::upsert()`.
     * WHERE / JOIN / ORDER BY set on the builder are ignored.
     *
     * @param array<string,mixed> $data
     * @param list<string>        $conflict
     * @param list<string>|null   $update
     */
    public function upsert(array $data, array $conflict, ?array $update = null): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException('upsert() requires at least one column');
        }
        $conflict = array_values($conflict);
        if ($conflict === []) {
            throw new \InvalidArgumentException('upsert() requires the conflict column(s) of a unique key');
        }
        $cols = array_keys($data);
        foreach ($conflict as $c) {
            if (!in_array($c, $cols, true)) {
                throw new \InvalidArgumentException("upsert(): conflict column '$c' is missing from the row");
            }
        }
        $update = $update === null
            ? array_values(array_diff($cols, $conflict))
            : array_values($update);
        foreach ($update as $c) {
            if (!in_array($c, $cols, true)) {
                throw new \InvalidArgumentException("upsert(): update column '$c' is missing from the row");
            }
        }

        $sql = 'INSERT INTO ' . $this->qualifyTable($this->table)
            . ' (' . implode(', ', array_map(fn (string $c) => $this->q($c), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        if ($this->driver === 'mysql') {
            // MariaDB only knows VALUES(col); MySQL deprecates it in
            // favour of a row alias (8.0.19+).
            $version = (string) $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $alias   = $update !== []
                && stripos($version, 'mariadb') === false
                && version_compare($version, '8.0.19', '>=');
            if ($alias) {
                $sql .= ' AS ' . $this->q('cloude_new');
            }
            // No "do nothing" form: assigning a key column to itself
            // changes nothing and raises no duplicate-key error.
            $sets = $update === []
                ? [$this->q($conflict[0]) . ' = ' . $this->q($conflict[0])]
                : array_map(
                    fn (string $c) => $this->q($c) . ' = '
                        . ($alias ? $this->q('cloude_new') . '.' . $this->q($c) : 'VALUES(' . $this->q($c) . ')'),
                    $update,
                );
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        } else {
            $sql .= ' ON CONFLICT (' . implode(', ', array_map(fn (string $c) => $this->q($c), $conflict)) . ')';
            $sql .= $update === []
                ? ' DO NOTHING'
                : ' DO UPDATE SET ' . implode(', ', array_map(
                    fn (string $c) => $this->q($c) . ' = EXCLUDED.' . $this->q($c),
                    $update,
                ));
        }

        StorageException::execute($this->pdo, $sql, array_values($data));
    }

    /**
     * UPDATE matching rows. Returns the number of affected rows.
     *
     * @param array<string,mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }
        [$sql, $params] = $this->buildUpdate($data);
        $stmt = StorageException::execute($this->pdo, $sql, $params);
        return $stmt->rowCount();
    }

    /**
     * DELETE matching rows. Returns the number of affected rows.
     */
    public function delete(): int
    {
        [$sql, $params] = $this->buildDelete();
        $stmt = StorageException::execute($this->pdo, $sql, $params);
        return $stmt->rowCount();
    }

    // ── debug helper ──────────────────────────────────────────────────────

    /**
     * Returns the SELECT SQL with bindings inlined as SQL literals — the
     * single string you'd paste into a SQL client to reproduce the query.
     * **For debugging only.** Never feed it back through PDO::exec().
     */
    public function compile(): string
    {
        [$sql, $params] = $this->buildSelect();
        return self::inlineBindings($sql, $params);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Wrap a SELECT terminal in the configured cache, when one was
     * requested via cache(). Otherwise the producer runs straight
     * through with no Cache lookup at all — zero overhead off the
     * happy path.
     *
     * @template T
     * @param list<mixed>  $params
     * @param callable():T $producer
     * @return T
     */
    private function remember(string $sql, array $params, callable $producer): mixed
    {
        if ($this->cacheTtl === null) {
            return $producer();
        }
        $store    = Cache::store($this->cacheStore);
        $key      = $this->cacheKey
            ?? 'cloude.q.' . sha1($this->connectionId() . '|' . $sql . '|' . serialize($params));
        $sentinel = self::class . '@miss';
        $hit = $store->get($key, $sentinel);
        if ($hit !== $sentinel) {
            return $hit;
        }
        $value = $producer();
        $store->set($key, $value, $this->cacheTtl);
        return $value;
    }

    /**
     * Identifies the connection inside an automatic cache key, so the
     * same SQL against two databases never shares an entry. A pooled
     * connection is known by its name in `Cloude\Storage\Connection`
     * (stable across requests); a PDO built by hand only by its driver.
     */
    private function connectionId(): string
    {
        $name = Connection::nameOf($this->pdo);
        return $name !== null
            ? 'conn:' . $name
            : 'driver:' . (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private function addWhere(string $conn, string $column, mixed $opOrValue, mixed $value, int $argCount): static
    {
        if ($argCount === 1) {
            throw new \InvalidArgumentException(
                "where() needs at least a value or operator (got just '$column')",
            );
        }
        if ($argCount === 2) {
            $op = is_string($opOrValue) && in_array(strtoupper($opOrValue), ['IS NULL', 'IS NOT NULL'], true)
                ? strtoupper($opOrValue)
                : '=';
            $val = $op === '=' ? $opOrValue : null;
        } else {
            $op  = is_string($opOrValue) ? strtoupper(trim($opOrValue)) : (string) $opOrValue;
            $val = $value;
        }

        if (!in_array($op, self::ALLOWED_OPS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported WHERE operator '$op' (allowed: " . implode(', ', self::ALLOWED_OPS) . ')',
            );
        }

        if ($val === null && in_array($op, ['=', '!=', '<>'], true)) {
            $op = $op === '=' ? 'IS NULL' : 'IS NOT NULL';
        }

        $this->wheres[] = WhereClause::predicate($conn, $column, $op, $val);
        return $this;
    }

    /**
     * @param callable(self): mixed $builder
     */
    private function addGroup(string $conn, callable $builder): static
    {
        $inner = new self($this->pdo, $this->table, $this->quoteChar);
        $builder($inner);
        if ($inner->wheres === []) {
            return $this;   // empty group is a no-op
        }
        $this->wheres[] = WhereClause::group($conn, $inner->wheres);
        return $this;
    }

    private function addJoin(string $type, string|TableRef $table, string $left, string $op, string $right): static
    {
        $op = trim($op);
        // Allow ON conditions on column-vs-column equality and the common
        // comparison ops only — never bind a value here.
        if (!in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new \InvalidArgumentException(
                "Unsupported JOIN operator '$op' (must be a comparison)",
            );
        }
        $this->joins[] = [
            'type'  => $type,
            'table' => $table,
            'left'  => $left,
            'op'    => $op,
            'right' => $right,
        ];
        return $this;
    }

    /**
     * @param  array<string,mixed> $data
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildUpdate(array $data): array
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = $this->q($col) . ' = ?';
            $params[] = $val;
        }
        [$tail, $tailParams] = $this->buildMutationTail();
        $sql = 'UPDATE ' . $this->qualifyTable($this->table)
            . ' SET ' . implode(', ', $set)
            . $tail;
        return [$sql, array_merge($params, $tailParams)];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildDelete(): array
    {
        [$tail, $params] = $this->buildMutationTail();
        return ['DELETE FROM ' . $this->qualifyTable($this->table) . $tail, $params];
    }

    /**
     * WHERE / ORDER BY / LIMIT for an UPDATE or DELETE.
     *
     * MySQL and SQLite take `ORDER BY ... LIMIT n` on the statement
     * itself. PostgreSQL does not, so there the rows are picked by a
     * sub-select on the row's physical id:
     *
     *   ... WHERE ctid IN (SELECT ctid FROM t WHERE ... ORDER BY ... LIMIT n)
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildMutationTail(): array
    {
        [$where, $params] = $this->buildWhere($this->wheres);
        $rest = $this->buildOrderBy() . $this->buildLimit();

        if ($this->driver === 'pgsql' && ($this->limit !== null || $this->offset !== null)) {
            $sql = ' WHERE ctid IN (SELECT ctid FROM ' . $this->qualifyTable($this->table)
                . $where . $rest . ')';
            return [$sql, $params];
        }
        return [$where . $rest, $params];
    }

    /**
     * @param list<mixed> $params
     */
    private static function inlineBindings(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }
        $i = 0;
        return (string) preg_replace_callback('/\?/', static function () use (&$i, $params): string {
            if (!array_key_exists($i, $params)) {
                return '?';
            }
            return self::quoteLiteral($params[$i++]);
        }, $sql);
    }

    private static function quoteLiteral(mixed $value): string
    {
        return match (true) {
            $value === null      => 'NULL',
            is_bool($value)      => $value ? '1' : '0',
            is_int($value),
            is_float($value)     => (string) $value,
            default              => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildSelect(): array
    {
        $quoted = $this->select === ['*']
            ? ['*']
            : array_map(fn (string $c) => $this->q($c), $this->select);
        $cols = implode(', ', [...$quoted, ...$this->selectRaw]);
        if ($cols === '') {
            $cols = '*';
        }

        $sql = 'SELECT ' . $cols . ' FROM ' . $this->qualifyTable($this->table);
        $sql .= $this->buildJoins();

        [$where, $params] = $this->buildWhere($this->wheres);
        $sql .= $where;
        $sql .= $this->buildGroupBy();
        $havingSql = $this->buildHaving($params);
        $sql .= $havingSql;
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimit();

        return [$sql, $params];
    }

    private function buildGroupBy(): string
    {
        if ($this->groups === []) {
            return '';
        }
        $parts = array_map(fn (string $c) => $this->q($c), $this->groups);
        return ' GROUP BY ' . implode(', ', $parts);
    }

    /**
     * Appends HAVING clauses to the running params list. Multiple
     * havingRaw() calls are AND-joined; each expression is wrapped in
     * parens so user-supplied OR / AND inside doesn't bleed into
     * sibling clauses.
     *
     * @param list<mixed> $params  Bound params accumulator (mutated)
     */
    private function buildHaving(array &$params): string
    {
        if ($this->havings === []) {
            return '';
        }
        $parts = [];
        foreach ($this->havings as $h) {
            $parts[] = '(' . $h['expr'] . ')';
            foreach ($h['bindings'] as $b) {
                $params[] = $b;
            }
        }
        return ' HAVING ' . implode(' AND ', $parts);
    }

    private function buildJoins(): string
    {
        if ($this->joins === []) {
            return '';
        }
        $parts = [];
        foreach ($this->joins as $j) {
            $expr = $this->qualifyTable($j['table']);
            if ($j['type'] === 'CROSS') {
                $parts[] = 'CROSS JOIN ' . $expr;
                continue;
            }
            $verb = $j['type'] === 'INNER' ? 'JOIN' : $j['type'] . ' JOIN';
            $parts[] = $verb . ' ' . $expr
                . ' ON ' . $this->q($j['left']) . ' ' . $j['op'] . ' ' . $this->q($j['right']);
        }
        return ' ' . implode(' ', $parts);
    }

    /**
     * @param list<WhereClause> $clauses
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(array $clauses, bool $top = true): array
    {
        if ($clauses === []) {
            return ['', []];
        }
        $parts  = [];
        $params = [];
        foreach ($clauses as $i => $w) {
            if ($w->isGroup) {
                [$inner, $innerParams] = $this->buildWhere($w->children, false);
                $inner = ltrim($inner);
                if ($inner === '' || $inner === '()') {
                    continue;
                }
                $expr = '(' . preg_replace('/^WHERE\s+/', '', $inner) . ')';
                foreach ($innerParams as $p) {
                    $params[] = $p;
                }
                $parts[] = ($i === 0 ? '' : $w->conn . ' ') . $expr;
                continue;
            }

            $col = $this->q($w->col);
            $op  = $w->op;
            $val = $w->val;

            $expr = match ($op) {
                'IS NULL', 'IS NOT NULL' => "$col $op",
                'IN' => is_array($val) && $val !== []
                    ? "$col IN (" . implode(', ', array_fill(0, count($val), '?')) . ')'
                    : '1=0',
                'NOT IN' => is_array($val) && $val !== []
                    ? "$col NOT IN (" . implode(', ', array_fill(0, count($val), '?')) . ')'
                    : '1=1',
                'BETWEEN', 'NOT BETWEEN' => "$col $op ? AND ?",
                default => "$col $op ?",
            };

            if ($op === 'IN' || $op === 'NOT IN') {
                if (is_array($val)) {
                    foreach ($val as $v) {
                        $params[] = $v;
                    }
                }
            } elseif ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
                if (is_array($val) && count($val) === 2) {
                    $params[] = $val[0];
                    $params[] = $val[1];
                }
            } elseif (!in_array($op, ['IS NULL', 'IS NOT NULL'], true)) {
                $params[] = $val;
            }

            $parts[] = ($i === 0 ? '' : $w->conn . ' ') . $expr;
        }
        if ($parts === []) {
            return ['', $params];
        }
        $prefix = $top ? ' WHERE ' : '';
        return [$prefix . implode(' ', $parts), $params];
    }

    private function buildOrderBy(): string
    {
        if ($this->orders === []) {
            return '';
        }
        $parts = [];
        foreach ($this->orders as $o) {
            $parts[] = $this->q($o['col']) . ' ' . $o['dir'];
        }
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function buildLimit(): string
    {
        $sql = '';
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /** Quote a column identifier — supports dotted (`u.email`) names. */
    private function q(string $identifier): string
    {
        return Identifier::qualify($identifier, $this->quoteChar);
    }

    /**
     * Render a FROM / JOIN target. Honors `TableRef` aliases, and also
     * accepts string forms — bare (`'users'`) or aliased (`'users AS u'`).
     */
    private function qualifyTable(string|TableRef $table): string
    {
        return $table instanceof TableRef
            ? $table->expression($this->quoteChar)
            : Identifier::qualify($table, $this->quoteChar);
    }

    /** For pluck/value: column results are keyed by the last dot segment. */
    private static function lastSegment(string $name): string
    {
        $dot = strrpos($name, '.');
        return $dot === false ? $name : substr($name, $dot + 1);
    }
}
