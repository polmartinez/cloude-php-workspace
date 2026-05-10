<?php

declare(strict_types=1);

/**
 * Recipe: extending Cloude\Data\* with project-specific repositories.
 *
 * The framework ships generic JSON / Markdown repositories. Projects
 * extend them to fix the base directory, normalize entity shapes via
 * `transform()`, and add domain-specific finders. Every method returns
 * a Cloude\Collection so the chainable API is one require away.
 *
 * Layout assumed by this example:
 *
 *   data/
 *     parties/
 *       psoe.json
 *       pp.json
 *       vox.json
 *     articles/
 *       intro.md          (or intro.md.gz)
 *       roadmap.md
 */

use Cloude\Collection;
use Cloude\Data\JsonRepository;
use Cloude\Data\MarkdownRepository;

// ── Parties: a JSON repository with a slug-aware shape ───────────────────────

class PartiesRepo extends JsonRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir . '/parties');
    }

    /**
     * Normalize each party into a stable shape, slug included so it
     * survives pluck()/groupBy() calls downstream.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    protected function transform(array $data, string $slug): array
    {
        return [
            'slug'     => $slug,
            'name'     => $data['nombre']             ?? $slug,
            'siglas'   => $data['siglas']             ?? null,
            'family'   => $data['familia_ideologica'] ?? null,
            'founded'  => $data['fundacion']          ?? null,
        ] + $data;
    }

    public function byFamily(string $family): Collection
    {
        return $this->all()->filter(fn ($p) => $p['family'] === $family);
    }
}

// ── Articles: a Markdown repository that flattens frontmatter ────────────────

class ArticlesRepo extends MarkdownRepository
{
    public function __construct(string $dataDir)
    {
        parent::__construct($dataDir . '/articles');
    }

    /**
     * Lift frontmatter onto the top level so callers can pluck('title')
     * or groupBy('lang') without diving into the meta sub-array.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    protected function transform(array $data, string $slug): array
    {
        return [
            'slug'        => $slug,
            'title'       => $data['meta']['title']     ?? $slug,
            'lang'        => $data['meta']['lang']      ?? 'es',
            'description' => $data['description']       ?? '',
            'html'        => $data['html']              ?? '',
            'meta'        => $data['meta']              ?? [],
        ];
    }

    public function byLang(string $lang): Collection
    {
        return $this->all()->filter(fn ($a) => $a['lang'] === $lang);
    }
}

// ── Use ──────────────────────────────────────────────────────────────────────

$parties  = new PartiesRepo(DATA_DIR);
$articles = new ArticlesRepo(DATA_DIR);

// Read one
$psoe = $parties->find('psoe');                // ?array
$psoe = $parties->findOr('psoe', ['name' => '?']);

// List slugs
$parties->slugs();                              // ['psoe', 'pp', ...]

// Iterate as a Collection — chainable API from Cloude\Collection
$parties->all()
    ->filter(fn ($p) => $p['family'] === 'left')
    ->sortBy('founded')
    ->take(5)
    ->pluck('name', 'slug');

// Domain-specific finder
$rightWing = $parties->byFamily('right');       // Collection

// Group by anything in the row
$byFamily = $parties->all()->groupBy('family')
    ->map(fn ($group) => array_column($group, 'name'))
    ->all();
//   ['right' => ['PP', 'VOX'], 'left' => ['PSOE']]

// Markdown side, same shape
$articles->byLang('es')
    ->pluck('title', 'slug')
    ->all();

// Atomic writes
$parties->write('new-party', ['nombre' => 'New', 'familia_ideologica' => 'centre']);
$articles->write('latest', "---\ntitle: Latest news\nlang: es\n---\n\nBody...");
