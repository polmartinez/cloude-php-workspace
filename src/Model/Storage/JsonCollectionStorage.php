<?php

declare(strict_types=1);

namespace Cloude\Model\Storage;

use Cloude\JsonFile;
use Cloude\Model\Storage;

/**
 * One JSON file IS the collection — a dict keyed by primary key.
 *
 *   /var/data/parties.json
 *   {
 *     "psoe": { "name": "Partido Socialista...", "founded": 1879, ... },
 *     "pp":   { "name": "Partido Popular",       "founded": 1989, ... },
 *     "vox":  { "name": "Vox",                   "founded": 2013, ... }
 *   }
 *
 * Contrast with `JsonStorage`, which puts one file per record in a
 * directory. Both implement the same `Storage` contract — pick whichever
 * matches the on-disk shape you have (or want).
 *
 * When to prefer this over `JsonStorage`:
 *   - Small / mid-size collections (10s–10,000s of rows). Every save
 *     rewrites the entire file, atomically (temp + rename).
 *   - You want the file to be human-editable as a single document.
 *   - The collection is already shaped this way and you don't want to
 *     migrate to one-file-per-record.
 *
 * When to prefer `JsonStorage` instead:
 *   - Large collections. Per-record files mean per-record writes.
 *   - You want rows to be independently editable / git-diffable.
 *
 * Conventions:
 *
 *   - The dict KEY is the primary key. Storage strips the PK from the
 *     value on write (it's redundant with the key) and re-injects it
 *     on read (so Model::id() works after find).
 *
 *   - Scalar values are wrapped on read as `['value' => $scalar, $pk => $id]`,
 *     so a translations-style `{"common.save": "Guardar"}` file is still
 *     queryable through the Storage interface (though for that specific
 *     shape, direct `JsonFile::read` is usually cleaner than going through
 *     Model — the wrapping is a fallback, not the intended use).
 *
 * Partitioning note (politica-esp use case): if your data is split across
 * many files (e.g. `data/{lang}/{country}/partidos.json`), one Model class
 * can only point at ONE connection / file. Either declare a class per
 * partition (verbose) or skip Model and use this adapter directly:
 *
 *   $storage = new JsonCollectionStorage("/var/data/es/europa/partidos.json");
 *   $psoe = $storage->find('psoe');
 */
final class JsonCollectionStorage implements Storage
{
    public function __construct(
        private string $filepath,
        private string $primaryKey = 'id',
    ) {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function find(mixed $id): ?array
    {
        $all = JsonFile::readOr($this->filepath, []);
        if (!array_key_exists($id, $all)) {
            return null;
        }
        return $this->wrapRow($id, $all[$id]);
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $all = JsonFile::readOr($this->filepath, []);

        $matches = [];
        foreach ($all as $key => $value) {
            $row = $this->wrapRow($key, $value);
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
        $all = JsonFile::readOr($this->filepath, []);
        if ($criteria === []) {
            return count($all);
        }
        $n = 0;
        foreach ($all as $key => $value) {
            if ($this->matches($this->wrapRow($key, $value), $criteria)) {
                $n++;
            }
        }
        return $n;
    }

    public function insert(array $data): mixed
    {
        if (!isset($data[$this->primaryKey])) {
            throw new \InvalidArgumentException(
                "JsonCollectionStorage::insert needs '{$this->primaryKey}' in the data "
                . '(the key IS the primary key — no auto-increment for this driver)',
            );
        }
        $id = $data[$this->primaryKey];
        unset($data[$this->primaryKey]);                 // don't duplicate inside the value

        $all = JsonFile::readOr($this->filepath, []);
        $all[$id] = $data;
        JsonFile::write($this->filepath, $all, pretty: true);

        return $id;
    }

    public function update(mixed $id, array $data): bool
    {
        $all = JsonFile::readOr($this->filepath, []);
        if (!array_key_exists($id, $all)) {
            return false;
        }
        $existing = is_array($all[$id]) ? $all[$id] : ['value' => $all[$id]];
        unset($data[$this->primaryKey]);                 // never store PK in the value
        $all[$id] = array_merge($existing, $data);
        return JsonFile::write($this->filepath, $all, pretty: true);
    }

    public function delete(mixed $id): bool
    {
        $all = JsonFile::readOr($this->filepath, []);
        if (!array_key_exists($id, $all)) {
            return false;
        }
        unset($all[$id]);
        return JsonFile::write($this->filepath, $all, pretty: true);
    }

    /**
     * Normalises a raw dict value into a row: arrays pass through,
     * scalars get wrapped, the PK gets injected so callers (and
     * Cloude\Model) always see it.
     *
     * @return array<string,mixed>
     */
    private function wrapRow(mixed $id, mixed $value): array
    {
        $row = is_array($value) ? $value : ['value' => $value];
        $row[$this->primaryKey] = $id;
        return $row;
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
}
