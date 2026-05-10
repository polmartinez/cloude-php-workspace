<?php

declare(strict_types=1);

namespace Cloude\Model\Storage;

use Cloude\JsonFile;
use Cloude\Model\Storage;
use Cloude\Str;

/**
 * One JSON file per row, named by primary key.
 *
 * Sits between `Cloude\Data\JsonRepository` (slug-keyed, document-style)
 * and `PdoStorage` (relational): you get the typed-instance ergonomics
 * of `Cloude\Model` while keeping the data on disk. Useful for:
 *
 *   - Tests where a real DB would be overkill but `ArrayStorage` doesn't
 *     exercise the JSON serialization round-trip.
 *   - Tiny apps that already use file-based storage and want typed
 *     entities for one specific concern (sessions, audit log, ...).
 *   - Local prototyping before committing to a SQL schema.
 *
 * `findBy()` reads every file in the directory once and filters in
 * memory — fine for hundreds of rows, not a substitute for a real DB
 * with indexes.
 *
 * IDs default to UUIDs (`Cloude\Str::uuid`). Pass `autoIncrement: true`
 * to get integer IDs computed from the highest existing filename.
 */
final class JsonStorage implements Storage
{
    public function __construct(
        private string $dir,
        private string $primaryKey = 'id',
        private bool $autoIncrement = false,
    ) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function find(mixed $id): ?array
    {
        return JsonFile::read($this->path($id));
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $rows = $this->loadAll();
        $matches = [];
        foreach ($rows as $row) {
            if ($this->matches($row, $criteria)) {
                $matches[] = $row;
            }
        }
        if ($orderBy !== null && $orderBy !== []) {
            usort($matches, static function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $col => $dir) {
                    $cmp = ($a[$col] ?? null) <=> ($b[$col] ?? null);
                    if ($cmp !== 0) {
                        return strtoupper((string) $dir) === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
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
            return count($this->files());
        }
        $n = 0;
        foreach ($this->loadAll() as $row) {
            if ($this->matches($row, $criteria)) {
                $n++;
            }
        }
        return $n;
    }

    public function insert(array $data): mixed
    {
        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = $this->autoIncrement
                ? $this->nextIntId()
                : Str::uuid();
        }
        $id = $data[$this->primaryKey];
        JsonFile::write($this->path($id), $data, pretty: true);
        return $id;
    }

    public function update(mixed $id, array $data): bool
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return false;
        }
        return JsonFile::write($this->path($id), array_merge($existing, $data), pretty: true);
    }

    public function delete(mixed $id): bool
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return false;
        }
        return @unlink($path);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return glob($this->dir . '/*.json') ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadAll(): array
    {
        $rows = [];
        foreach ($this->files() as $f) {
            $row = JsonFile::read($f);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
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

    private function nextIntId(): int
    {
        $max = 0;
        foreach ($this->files() as $f) {
            $name = basename($f, '.json');
            if (ctype_digit($name) && (int) $name > $max) {
                $max = (int) $name;
            }
        }
        return $max + 1;
    }

    private function path(mixed $id): string
    {
        return $this->dir . '/' . (string) $id . '.json';
    }
}
