<?php

declare(strict_types=1);

namespace Cloude\Data;

use Cloude\Collection;

/**
 * Base class for "directory-of-files" repositories: one entity per file,
 * named by slug, all under a single base directory. Subclasses pick the
 * file format (JSON, Markdown, ...) by implementing `find()`, `slugs()`
 * and `path()`. Everything else — `exists`, `findOr`, `all`, the
 * `transform()` hook, the `Collection` integration — comes for free.
 *
 * Typical usage:
 *
 *   class PartiesRepo extends \Cloude\Data\JsonRepository
 *   {
 *       public function __construct(string $country)
 *       {
 *           parent::__construct(DATA_DIR . "/scopes/{$country}/parties");
 *       }
 *
 *       protected function transform(array $data, string $slug): array
 *       {
 *           return [
 *               'slug'   => $slug,
 *               'name'   => $data['nombre'] ?? $slug,
 *               'family' => $data['familia_ideologica'] ?? null,
 *           ] + $data;
 *       }
 *
 *       public function byFamily(string $family): \Cloude\Collection
 *       {
 *           return $this->all()->filter(fn ($p) => $p['family'] === $family);
 *       }
 *   }
 *
 * The base directory is read on demand. There is no in-memory cache at
 * this level — the underlying `Cloude\JsonFile` already de-duplicates
 * per-request reads.
 */
abstract class Repository
{
    protected string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * Reads and parses one entity by slug. Returns null when the entity
     * is missing or unreadable; subclasses must NOT throw for "not found".
     *
     * @return array<mixed>|null
     */
    abstract public function find(string $slug): ?array;

    /**
     * Lists every available slug in the directory (filenames stripped of
     * extension). Order is filesystem-defined; sort in the subclass if
     * order matters.
     *
     * @return array<int,string>
     */
    abstract public function slugs(): array;

    /**
     * Returns the on-disk path that backs $slug. Used by `exists()`.
     */
    abstract protected function path(string $slug): string;

    /**
     * True when an entity for $slug exists on disk.
     */
    public function exists(string $slug): bool
    {
        return file_exists($this->path($slug));
    }

    /**
     * `find()` with a default fallback for callers that always want an array.
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public function findOr(string $slug, array $default = []): array
    {
        return $this->find($slug) ?? $default;
    }

    /**
     * Reads every entity in the directory and returns a Collection keyed
     * by slug. Each entry is run through `transform()` so subclasses can
     * normalize shapes consistently.
     */
    public function all(): Collection
    {
        $items = [];
        foreach ($this->slugs() as $slug) {
            $data = $this->find($slug);
            if ($data === null) {
                continue;
            }
            $items[$slug] = $this->transform($data, $slug);
        }
        return new Collection($items);
    }

    /**
     * Hook for subclasses to normalize each entity after reading. By
     * default, attaches the slug under the `_slug` key so callers can
     * reach it after pluck/groupBy without losing the bucket label.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    protected function transform(array $data, string $slug): array
    {
        return $data + ['_slug' => $slug];
    }
}
