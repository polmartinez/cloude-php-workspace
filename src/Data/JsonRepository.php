<?php

declare(strict_types=1);

namespace Cloude\Data;

use Cloude\JsonFile;

/**
 * Repository over a directory of `.json` files, one per entity.
 *
 *   $repo = new JsonRepository(__DIR__ . '/data/parties');
 *   $repo->slugs();          // ['psoe', 'pp', 'vox', ...]
 *   $repo->find('psoe');     // ?array
 *   $repo->all();            // Collection keyed by slug
 *
 * Reading and writing both go through `Cloude\JsonFile`, so the per-request
 * read cache and the atomic-write semantics are inherited automatically.
 *
 * Subclass to tighten types or add domain-specific finders. Override
 * `transform()` to normalize entity shapes, or `path()` to change the
 * filename layout (for example, sharded sub-directories).
 */
class JsonRepository extends Repository
{
    public function __construct(string $baseDir, protected string $extension = '.json')
    {
        parent::__construct($baseDir);
    }

    /**
     * @return array<mixed>|null
     */
    public function find(string $slug): ?array
    {
        return JsonFile::read($this->path($slug));
    }

    /**
     * @return array<int,string>
     */
    public function slugs(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }
        $files = glob($this->baseDir . '/*' . $this->extension) ?: [];
        $ext = $this->extension;
        return array_values(array_map(
            static fn (string $f): string => basename($f, $ext),
            $files,
        ));
    }

    /**
     * Atomic write — delegated to `JsonFile::write` (UNESCAPED_UNICODE +
     * UNESCAPED_SLASHES, atomic temp+rename). Returns false when the dir
     * doesn't exist or the encode fails.
     *
     * @param array<mixed> $data
     */
    public function write(string $slug, array $data, bool $pretty = true): bool
    {
        return JsonFile::write($this->path($slug), $data, $pretty);
    }

    protected function path(string $slug): string
    {
        return $this->baseDir . '/' . $slug . $this->extension;
    }
}
