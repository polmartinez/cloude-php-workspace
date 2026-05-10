<?php

declare(strict_types=1);

namespace Cloude\Model\Storage;

use Cloude\Format;
use Cloude\Markdown;
use Cloude\Markdown\File;
use Cloude\Model\Storage;

/**
 * One Markdown file per record. Reads transparently through
 * `Cloude\Markdown\File` so `.md.gz` (gzipped) is handled automatically.
 *
 * Disk layout:
 *
 *   $dir/{primaryKey}.md         (or .md.gz)
 *
 * Each "row" returned by find()/findBy() is the shape `Markdown::parse()`
 * produces, plus the primary-key field injected by the adapter:
 *
 *   [
 *     'meta'        => [...frontmatter...],
 *     'html'        => '<p>...</p>',
 *     'raw'         => 'body without frontmatter',
 *     'paragraphs'  => [...],
 *     'description' => '...',
 *     'noindex'     => bool,
 *     '<primaryKey>' => '<id>',
 *   ]
 *
 * Writes accept a row shaped as `{meta: [...], raw: '...body...'}` (the
 * primary key is taken from the dict's PK field) and serialise as
 * YAML-frontmatter + body via `Cloude\Format::yamlEncode`.
 *
 * findBy() iterates and parses every file in the directory — fine for
 * tens to low hundreds of records, suspect on much larger collections.
 *
 * For consumers who just want the raw on-disk content (the full file
 * including frontmatter, no parsing), call `raw($id)` — useful for
 * passing through to callers that expect `Md::read($path)` semantics.
 */
final class MarkdownStorage implements Storage
{
    public function __construct(
        private string $dir,
        private string $primaryKey = 'slug',
    ) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public function find(mixed $id): ?array
    {
        $path = $this->path($id);
        if (!File::exists($path)) {
            return null;
        }
        $body = File::read($path);
        if ($body === '') {
            return null;
        }
        $parsed = Markdown::parse($body);
        $parsed[$this->primaryKey] = $id;
        return $parsed;
    }

    /**
     * Returns the raw file content (frontmatter included, no parsing).
     * Off-interface helper for migration paths that previously called
     * `Cloude\Markdown\File::read($path)` directly.
     */
    public function raw(mixed $id): string
    {
        return File::read($this->path($id));
    }

    public function findBy(
        array $criteria = [],
        ?int $limit = null,
        ?int $offset = null,
        ?array $orderBy = null,
    ): array {
        $rows = [];
        foreach ($this->slugs() as $slug) {
            $row = $this->find($slug);
            if ($row !== null && $this->matches($row, $criteria)) {
                $rows[] = $row;
            }
        }
        if ($orderBy !== null && $orderBy !== []) {
            usort($rows, function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $col => $dir) {
                    $av = $a[$col] ?? ($a['meta'][$col] ?? null);
                    $bv = $b[$col] ?? ($b['meta'][$col] ?? null);
                    $cmp = $av <=> $bv;
                    if ($cmp !== 0) {
                        return strtoupper((string) $dir) === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }
        if ($offset !== null) {
            $rows = array_slice($rows, $offset);
        }
        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
        }
        return array_values($rows);
    }

    public function count(array $criteria = []): int
    {
        if ($criteria === []) {
            return count($this->slugs());                          // fast path: count files
        }
        $n = 0;
        foreach ($this->slugs() as $slug) {
            $row = $this->find($slug);
            if ($row !== null && $this->matches($row, $criteria)) {
                $n++;
            }
        }
        return $n;
    }

    public function insert(array $data): mixed
    {
        if (!isset($data[$this->primaryKey])) {
            throw new \InvalidArgumentException(
                "MarkdownStorage::insert needs '{$this->primaryKey}' in the data",
            );
        }
        $id = $data[$this->primaryKey];
        File::write($this->path($id), $this->serialize($data));
        return $id;
    }

    public function update(mixed $id, array $data): bool
    {
        if (!File::exists($this->path($id))) {
            return false;
        }
        $existing = $this->find($id) ?? [];
        $merged = array_merge($existing, $data);
        return File::write($this->path($id), $this->serialize($merged));
    }

    public function delete(mixed $id): bool
    {
        $resolved = File::resolve($this->path($id));
        if ($resolved === null) {
            return false;
        }
        return @unlink($resolved);
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        $plain = glob($this->dir . '/*.md') ?: [];
        $gz    = glob($this->dir . '/*.md.gz') ?: [];
        $slugs = [];
        foreach ($plain as $f) {
            $slugs[] = basename($f, '.md');
        }
        foreach ($gz as $f) {
            $slugs[] = basename($f, '.md.gz');
        }
        return array_values(array_unique($slugs));
    }

    private function path(mixed $id): string
    {
        return $this->dir . '/' . (string) $id . '.md';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $criteria
     */
    private function matches(array $row, array $criteria): bool
    {
        foreach ($criteria as $col => $val) {
            $rowVal = $row[$col] ?? ($row['meta'][$col] ?? null);
            if ($rowVal !== $val) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function serialize(array $data): string
    {
        $meta = $data['meta'] ?? [];
        $body = (string) ($data['raw'] ?? $data['body'] ?? '');

        // Strip framework-computed / PK fields; anything else top-level
        // gets folded into the frontmatter as a convenience.
        unset(
            $data['meta'], $data['raw'], $data['body'],
            $data['html'], $data['paragraphs'], $data['description'], $data['noindex'],
            $data[$this->primaryKey],
        );
        $meta = array_merge($meta, $data);

        if ($meta === []) {
            return $body;
        }
        return "---\n" . Format::yamlEncode($meta) . "---\n\n" . $body;
    }
}
