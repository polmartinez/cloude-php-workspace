<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Minimal SQL query builder. SELECT / INSERT / UPDATE / DELETE plus
 * WHERE, ORDER BY, LIMIT, OFFSET. Defaults to backtick identifier
 * quoting (MySQL / SQLite) — pass `'"'` as the third constructor arg
 * for Postgres (or `Identifier::quoteCharFor($pdo)` to auto-detect).
 *
 * Designed to cover the 80% of queries you write by hand without
 * re-implementing a full SQL grammar. **Does not** ship:
 *
 *   - JOINs, UNIONs, subqueries
 *   - Aggregations beyond `count()`
 *   - Window functions, CTEs, SAVEPOINTs
 *   - Nested AND/OR groups via callables
 *
 * For any of that, drop down to the PDO connection (`Cloude\Db\Query`
 * doesn't hide it — pass your `\PDO` in directly) and write the SQL.
 *
 * Construction:
 *
 *   $q = new Query($pdo, 'users');
 *   // or, when you have a Cloude\Model:
 *   $q = User::storage()->query();
 *   $q = User::query();
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
 *   $q->select('email')->where(...)->pluck('email');           // list<string>
 *   $q->select('id', 'name')->pluck('name', 'id');             // [id => name, ...]
 *   $q->select('email')->where(...)->value('email');           // first scalar
 *
 * WHERE variants:
 *
 *   $q->where('age', '>', 18);              // explicit op
 *   $q->where('email', 'a@b.com');          // shorthand for op '='
 *   $q->where('email', '=', null);          // auto-rendered as IS NULL
 *   $q->whereIn('id', [1, 2, 3]);
 *   $q->whereNotIn('id', [...]);
 *   $q->whereNull('deleted_at');
 *   $q->whereNotNull('email');
 *   $q->whereBetween('age', 18, 65);
 *   $q->orWhere('role', 'admin');           // OR-joined to previous predicate
 *
 * Allowed operators (everything else throws):
 *   = != <> < <= > >= LIKE NOT LIKE IN NOT IN BETWEEN NOT BETWEEN
 *   IS NULL IS NOT NULL
 *
 * Mutation (uses the current WHERE):
 *
 *   $id     = $q->insert(['name' => 'Ada', ...]);   // last-insert-id (or null)
 *   $count  = $q->where(...)->update(['active' => 0]);  // affected rows
 *   $count  = $q->where(...)->delete();                  // affected rows
 *
 * UPDATE and DELETE both accept ORDER BY + LIMIT, but those are
 * MySQL/SQLite-specific (Postgres rejects them). You'll know because
 * Postgres throws on execute. The builder won't second-guess your DB.
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
        'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'IS NULL', 'IS NOT NULL',
    ];

    /** @var list<string> */
    private array $select = ['*'];

    /** @var list<array{conn:string, col:string, op:string, val:mixed}> */
    private array $wheres = [];

    /** @var list<array{col:string, dir:string}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(
        private \PDO $pdo,
        private string $table,
        private string $quoteChar = '`',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    // ── builder methods ────────────────────────────────────────────────────

    /**
     * Pick which columns to SELECT. Pass none to reset to `*`.
     */
    public function select(string ...$columns): static
    {
        $this->select = $columns === [] ? ['*'] : array_values($columns);
        return $this;
    }

    /**
     * AND-WHERE. Three call shapes:
     *
     *   where('col', '=', $value)   // explicit op
     *   where('col', $value)        // shorthand for '='
     *   where('col', 'IS NULL')     // op-only (no value)
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
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['conn' => 'AND', 'col' => $column, 'op' => 'IN', 'val' => array_values($values)];
        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['conn' => 'AND', 'col' => $column, 'op' => 'NOT IN', 'val' => array_values($values)];
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['conn' => 'AND', 'col' => $column, 'op' => 'IS NULL', 'val' => null];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['conn' => 'AND', 'col' => $column, 'op' => 'IS NOT NULL', 'val' => null];
        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high): static
    {
        $this->wheres[] = ['conn' => 'AND', 'col' => $column, 'op' => 'BETWEEN', 'val' => [$low, $high]];
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

    // ── execution: SELECT ─────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        [$sql, $params] = $this->buildSelect();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /**
     * Returns the first matching row (after applying ORDER BY) or null.
     * Internally clamps LIMIT to 1 for the duration of the call.
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
        [$where, $params] = $this->buildWhere();
        $sql = 'SELECT COUNT(*) FROM ' . $this->q($this->table) . $where;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the value at $column from the first matching row, or null.
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        return $row[$column] ?? null;
    }

    /**
     * Extracts a column from every matching row. Optionally re-keys the
     * result by another column.
     *
     *   $q->pluck('email')           → list<string>
     *   $q->pluck('name', 'id')      → [id => name, ...]
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $cols = $key !== null ? [$column, $key] : [$column];
        $rows = $this->select(...$cols)->get();

        if ($key === null) {
            return array_column($rows, $column);
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row[$key]] = $row[$column];
        }
        return $out;
    }

    // ── execution: mutations ──────────────────────────────────────────────

    /**
     * INSERT one row. Returns the auto-generated primary key (or null).
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): mixed
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() requires at least one column');
        }
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->q($this->table)
            . ' (' . implode(', ', array_map(fn (string $c) => $this->q($c), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));

        $last = $this->pdo->lastInsertId();
        if ($last === '' || $last === false || $last === '0') {
            return null;
        }
        return ctype_digit($last) ? (int) $last : $last;
    }

    /**
     * UPDATE matching rows. Returns the number of affected rows.
     *
     * Note: with no preceding `where()` call, this updates EVERY row —
     * just like raw SQL. That's intentional but worth noticing.
     *
     * @param array<string,mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }
        [$sql, $params] = $this->buildUpdate($data);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * DELETE matching rows. Returns the number of affected rows.
     *
     * Note: with no preceding `where()` call, this empties the table.
     */
    public function delete(): int
    {
        [$sql, $params] = $this->buildDelete();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ── debug helper ──────────────────────────────────────────────────────

    /**
     * Returns the SELECT SQL with bindings inlined as SQL literals — the
     * single string you'd paste into a SQL client to reproduce the query.
     *
     * **For debugging only.** Never feed the output back through
     * `PDO::exec()` or `query()`: prepared statements are the only safe
     * path. The escaping here is good enough to read with your eyes;
     * it's not a hardening boundary.
     *
     *   echo $q->where('age', '>', 18)->compile();
     *   // → SELECT * FROM `users` WHERE `age` > 18
     */
    public function compile(): string
    {
        [$sql, $params] = $this->buildSelect();
        return self::inlineBindings($sql, $params);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function addWhere(string $conn, string $column, mixed $opOrValue, mixed $value, int $argCount): static
    {
        // Normalise the call shape into (op, value).
        if ($argCount === 1) {
            throw new \InvalidArgumentException(
                "where() needs at least a value or operator (got just '$column')",
            );
        }
        if ($argCount === 2) {
            // where('col', $val) shorthand → equality
            // (or where('col', 'IS NULL') / where('col', 'IS NOT NULL'))
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

        // Auto-translate "= NULL" / "!= NULL" to IS [NOT] NULL — the
        // SQL-correct version. Equality vs NULL always returns NULL otherwise.
        if ($val === null && in_array($op, ['=', '!=', '<>'], true)) {
            $op = $op === '=' ? 'IS NULL' : 'IS NOT NULL';
        }

        $this->wheres[] = ['conn' => $conn, 'col' => $column, 'op' => $op, 'val' => $val];
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
        [$where, $whereParams] = $this->buildWhere();
        $sql = 'UPDATE ' . $this->q($this->table)
            . ' SET ' . implode(', ', $set)
            . $where
            . $this->buildOrderBy()
            . $this->buildLimit();
        return [$sql, array_merge($params, $whereParams)];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildDelete(): array
    {
        [$where, $params] = $this->buildWhere();
        $sql = 'DELETE FROM ' . $this->q($this->table)
            . $where
            . $this->buildOrderBy()
            . $this->buildLimit();
        return [$sql, $params];
    }

    /**
     * Substitutes each `?` in $sql with a SQL-literal rendering of the
     * matching $param. For debug output only — see compile()'s warning.
     *
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
                return '?';                                 // shouldn't happen if buildX was well-formed
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
        $cols = $this->select === ['*']
            ? '*'
            : implode(', ', array_map(fn (string $c) => $this->q($c), $this->select));

        $sql = 'SELECT ' . $cols . ' FROM ' . $this->q($this->table);

        [$where, $params] = $this->buildWhere();
        $sql .= $where;
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimit();

        return [$sql, $params];
    }

    /**
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }
        $parts  = [];
        $params = [];
        foreach ($this->wheres as $i => $w) {
            $col = $this->q($w['col']);
            $op  = $w['op'];
            $val = $w['val'];

            $expr = match ($op) {
                'IS NULL', 'IS NOT NULL' => "$col $op",
                'IN' => is_array($val) && $val !== []
                    ? "$col IN (" . implode(', ', array_fill(0, count($val), '?')) . ')'
                    : '1=0',                                            // empty IN → never matches
                'NOT IN' => is_array($val) && $val !== []
                    ? "$col NOT IN (" . implode(', ', array_fill(0, count($val), '?')) . ')'
                    : '1=1',                                            // empty NOT IN → always matches
                'BETWEEN', 'NOT BETWEEN' => "$col $op ? AND ?",
                default => "$col $op ?",
            };

            // Collect bind params in the same order as ?-placeholders above.
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

            $parts[] = ($i === 0 ? '' : $w['conn'] . ' ') . $expr;
        }
        return [' WHERE ' . implode(' ', $parts), $params];
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

    private function q(string $identifier): string
    {
        return Identifier::quote($identifier, $this->quoteChar);
    }
}
