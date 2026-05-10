<?php

declare(strict_types=1);

namespace Cloude\Model\Storage;

use Cloude\Model\Storage;

/**
 * In-memory `Storage` adapter — no I/O, no dependencies.
 *
 * Designed for unit tests: configure your model against an `ArrayStorage`
 * seeded with fixtures and you can exercise CRUD without a database.
 *
 *   $storage = new ArrayStorage([
 *       ['id' => 1, 'email' => 'ada@example.com',  'name' => 'Ada'],
 *       ['id' => 2, 'email' => 'alan@example.com', 'name' => 'Alan'],
 *   ]);
 *   User::configure($storage);
 *
 * Auto-incrementing integer IDs are issued when `insert()` is called
 * without an explicit primary-key value.
 */
final class ArrayStorage implements Storage
{
    /** @var array<int|string, array<string,mixed>> */
    private array $rows = [];

    private int $autoIncrement = 1;

    /**
     * @param list<array<string,mixed>> $seed
     */
    public function __construct(array $seed = [], private string $primaryKey = 'id')
    {
        foreach ($seed as $row) {
            if (!isset($row[$this->primaryKey])) {
                $row[$this->primaryKey] = $this->autoIncrement++;
            }
            $id = $row[$this->primaryKey];
            $this->rows[$id] = $row;
            if (is_int($id) && $id >= $this->autoIncrement) {
                $this->autoIncrement = $id + 1;
            }
        }
    }

    public function find(mixed $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $matches = [];
        foreach ($this->rows as $row) {
            if ($this->matches($row, $criteria)) {
                $matches[] = $row;
            }
        }
        if ($orderBy !== null) {
            $matches = self::sortRows($matches, $orderBy);
        }
        if ($offset !== null) {
            $matches = array_slice($matches, $offset);
        }
        if ($limit !== null) {
            $matches = array_slice($matches, 0, $limit);
        }
        return array_values($matches);
    }

    public function count(array $criteria = []): int
    {
        if ($criteria === []) {
            return count($this->rows);
        }
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->matches($row, $criteria)) {
                $n++;
            }
        }
        return $n;
    }

    public function insert(array $data): mixed
    {
        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = $this->autoIncrement++;
        } else {
            $id = $data[$this->primaryKey];
            if (is_int($id) && $id >= $this->autoIncrement) {
                $this->autoIncrement = $id + 1;
            }
        }
        $id = $data[$this->primaryKey];
        $this->rows[$id] = $data;
        return $id;
    }

    public function update(mixed $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return true;
    }

    public function delete(mixed $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        unset($this->rows[$id]);
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $criteria
     */
    private function matches(array $row, array $criteria): bool
    {
        foreach ($criteria as $col => $val) {
            if (($row[$col] ?? null) !== $val) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param  list<array<string,mixed>>     $rows
     * @param  array<string,'ASC'|'DESC'>    $orderBy
     * @return list<array<string,mixed>>
     */
    private static function sortRows(array $rows, array $orderBy): array
    {
        usort($rows, static function (array $a, array $b) use ($orderBy): int {
            foreach ($orderBy as $col => $dir) {
                $cmp = ($a[$col] ?? null) <=> ($b[$col] ?? null);
                if ($cmp !== 0) {
                    return strtoupper((string) $dir) === 'DESC' ? -$cmp : $cmp;
                }
            }
            return 0;
        });
        return $rows;
    }
}
