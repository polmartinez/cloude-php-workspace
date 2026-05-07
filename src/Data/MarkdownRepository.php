<?php

declare(strict_types=1);

namespace Cloude\Data;

use Cloude\Markdown;
use Cloude\Markdown\File;

/**
 * Repository over a directory of `.md` (or `.md.gz`) files, one per entity.
 *
 *   $repo = new MarkdownRepository(__DIR__ . '/content/articles');
 *   $repo->slugs();           // ['intro', 'roadmap', ...]
 *   $repo->find('intro');     // parsed array — frontmatter + body html
 *   $repo->raw('intro');      // string — raw markdown content
 *   $repo->all();             // Collection keyed by slug
 *
 * `find()` returns the same shape as `Cloude\Markdown::parse()`:
 * `['meta' => [...], 'html' => '...', 'paragraphs' => [...], 'raw' => '...',
 *   'description' => '...', 'noindex' => bool]`.
 *
 * Both plain `.md` and gzipped `.md.gz` work transparently through
 * `Cloude\Markdown\File` — slug stays the same in either case.
 *
 * Override `transform()` to flatten metadata into the row (e.g. so callers
 * can `pluck('meta.title')` or `groupBy('meta.lang')` on the resulting
 * Collection without nested key gymnastics).
 */
class MarkdownRepository extends Repository
{
    public function __construct(string $baseDir, protected string $extension = '.md')
    {
        parent::__construct($baseDir);
    }

    /**
     * @return array<mixed>|null
     */
    public function find(string $slug): ?array
    {
        $path = $this->path($slug);
        if (!File::exists($path)) {
            return null;
        }
        $raw = File::read($path);
        if ($raw === '') {
            return null;
        }
        return Markdown::parse($raw);
    }

    /**
     * Returns the raw markdown source (decompressed if needed), or null
     * when the entity is missing. Useful when you want to serve the
     * markdown verbatim instead of the parsed structure.
     */
    public function raw(string $slug): ?string
    {
        $path = $this->path($slug);
        if (!File::exists($path)) {
            return null;
        }
        $raw = File::read($path);
        return $raw === '' ? null : $raw;
    }

    public function exists(string $slug): bool
    {
        return File::exists($this->path($slug));
    }

    /**
     * @return array<int,string>
     */
    public function slugs(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }
        $plain   = glob($this->baseDir . '/*' . $this->extension) ?: [];
        $gzipped = glob($this->baseDir . '/*' . $this->extension . '.gz') ?: [];
        $slugs = [];
        foreach ($plain as $f) {
            $slugs[] = basename($f, $this->extension);
        }
        foreach ($gzipped as $f) {
            $slugs[] = basename($f, $this->extension . '.gz');
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Atomic write via `Markdown\File::write` — stores as `.md.gz` and
     * removes any plain-`.md` twin to keep the directory clean.
     */
    public function write(string $slug, string $content): bool
    {
        return File::write($this->path($slug), $content);
    }

    protected function path(string $slug): string
    {
        return $this->baseDir . '/' . $slug . $this->extension;
    }
}
