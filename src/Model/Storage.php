<?php

declare(strict_types=1);

namespace Cloude\Model;

/**
 * Persistence contract for `Cloude\Model`.
 *
 * The interface every storage adapter implements: `PdoStorage` (MySQL /
 * Postgres / SQLite), `JsonStorage` (one file per row), `ArrayStorage`
 * (in-memory, for tests).
 *
 * Intentionally narrow:
 *   - `find()` is by primary key.
 *   - `findBy()` is equality only — `['active' => 1, 'role' => 'admin']`.
 *   - `count()`  is equality only.
 *   - For richer queries (LIKE, IN, ranges, joins, aggregations) drop
 *     down to the adapter's native API. `PdoStorage::pdo()` exposes the
 *     PDO connection for raw SQL.
 *
 * This is the "no query builder, no DSL" line in the framework's
 * persistence philosophy. If you'd ship a Criteria object, you'd ship
 * an ORM — and that's the line we're not crossing.
 */
interface Storage
{
    /**
     * Returns the row with the given primary-key value, or null if missing.
     *
     * @return array<string,mixed>|null
     */
    public function find(mixed $id): ?array;

    /**
     * Returns rows matching every column in $criteria (equality, AND).
     *
     * Empty $criteria returns every row (paged by limit/offset/orderBy).
     *
     * @param  array<string,mixed>          $criteria
     * @param  array<string,'ASC'|'DESC'>|null $orderBy
     * @return list<array<string,mixed>>
     */
    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array;

    /**
     * @param array<string,mixed> $criteria
     */
    public function count(array $criteria = []): int;

    /**
     * Persists a new row. Returns the primary-key value, either auto-assigned
     * by the backend (e.g. AUTO_INCREMENT) or the value already present in
     * `$data[$primaryKey]`.
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): mixed;

    /**
     * @param array<string,mixed> $data
     */
    public function update(mixed $id, array $data): bool;

    public function delete(mixed $id): bool;
}
