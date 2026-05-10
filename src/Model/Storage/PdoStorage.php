<?php

declare(strict_types=1);

namespace Cloude\Model\Storage;

use Cloude\Model\Storage;
use Cloude\Storage\Identifier;
use Cloude\Storage\Query;

/**
 * PDO-backed `Storage` adapter for MySQL, SQLite and Postgres.
 *
 * Identifier quoting auto-detects the driver: backticks for MySQL /
 * SQLite, double quotes for Postgres. Override `$quoteChar` if you need
 * something else.
 *
 * The adapter intentionally does not parse SQL or build a query DSL —
 * `findBy()` and `count()` accept equality predicates only. For richer
 * queries (LIKE, IN, ranges, joins, aggregations), reach the underlying
 * connection via `pdo()` and write SQL:
 *
 *   $rows = User::storage()->pdo()
 *       ->query('SELECT id, name FROM users WHERE age > 18')
 *       ->fetchAll(\PDO::FETCH_ASSOC);
 *
 * That escape hatch is the point: we ship the 80% case and keep the
 * other 20% one method call away from raw SQL.
 *
 * Identifier safety: column names and the table name go through a
 * `[A-Za-z_][A-Za-z0-9_]*` whitelist before being interpolated into the
 * SQL. Anything else throws `InvalidArgumentException`.
 */
final class PdoStorage implements Storage
{
    public function __construct(
        private \PDO $pdo,
        private string $table,
        private string $primaryKey = 'id',
        private string $quoteChar = '`',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Escape hatch for raw SQL. Use when neither `findBy()` nor the
     * `Cloude\Db\Query` builder cover what you need — joins, unions,
     * subqueries, aggregations, transactions.
     */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Returns a fresh `Cloude\Db\Query` bound to this storage's table
     * and quote character. The intended escape hatch when `findBy()`'s
     * equality predicates aren't enough — `>`, `<`, `LIKE`, `IN`,
     * `BETWEEN`, `IS NULL`, multi-column `ORDER BY`, and so on.
     *
     *   User::storage()->query()
     *       ->where('age', '>', 18)
     *       ->whereIn('role', ['admin', 'editor'])
     *       ->orderBy('created_at', 'DESC')
     *       ->limit(20)
     *       ->get();
     */
    public function query(): Query
    {
        return new Query($this->pdo, $this->table, $this->quoteChar);
    }

    public function find(mixed $id): ?array
    {
        $sql = 'SELECT * FROM ' . $this->q($this->table)
            . ' WHERE ' . $this->q($this->primaryKey) . ' = ? LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        [$whereSql, $params] = $this->buildWhere($criteria);

        $sql = 'SELECT * FROM ' . $this->q($this->table) . $whereSql;

        if ($orderBy !== null && $orderBy !== []) {
            $parts = [];
            foreach ($orderBy as $col => $dir) {
                $dir = strtoupper((string) $dir) === 'DESC' ? 'DESC' : 'ASC';
                $parts[] = $this->q((string) $col) . ' ' . $dir;
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, (int) $limit);
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . max(0, (int) $offset);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    public function count(array $criteria = []): int
    {
        [$whereSql, $params] = $this->buildWhere($criteria);
        $sql = 'SELECT COUNT(*) FROM ' . $this->q($this->table) . $whereSql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): mixed
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() needs at least one column');
        }

        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->q($this->table)
            . ' (' . implode(', ', array_map($this->q(...), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));

        if (array_key_exists($this->primaryKey, $data)) {
            return $data[$this->primaryKey];
        }
        $last = $this->pdo->lastInsertId();
        return $last === '' || $last === false || $last === '0'
            ? null
            : (ctype_digit($last) ? (int) $last : $last);
    }

    public function update(mixed $id, array $data): bool
    {
        if ($data === []) {
            return true;
        }
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = $this->q((string) $col) . ' = ?';
            $params[] = $val;
        }
        $params[] = $id;
        $sql = 'UPDATE ' . $this->q($this->table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $this->q($this->primaryKey) . ' = ?';
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(mixed $id): bool
    {
        $sql = 'DELETE FROM ' . $this->q($this->table)
            . ' WHERE ' . $this->q($this->primaryKey) . ' = ?';
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * @param  array<string,mixed> $criteria
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(array $criteria): array
    {
        if ($criteria === []) {
            return ['', []];
        }
        $where = [];
        $params = [];
        foreach ($criteria as $col => $val) {
            if ($val === null) {
                $where[] = $this->q((string) $col) . ' IS NULL';
                continue;
            }
            $where[] = $this->q((string) $col) . ' = ?';
            $params[] = $val;
        }
        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Whitelist + quote a SQL identifier. Delegated to the shared helper
     * so PdoStorage and the Query builder stay in sync.
     */
    private function q(string $identifier): string
    {
        return Identifier::quote($identifier, $this->quoteChar);
    }
}
