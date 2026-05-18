# Cloude Framework

A minimalist PHP micro-framework. No magic, no service container. Persistence is opt-in: file-based (JSON / Markdown) by default, with a thin Active Record over PDO via `Cloude\Model` when you need a relational database.

- **PHP 8.3+** (tested on 8.3, 8.4 and 8.5)
- **PSR-4** autoloading, namespace `Cloude\`
- **PSR-12 / PER-CS 2.0** coding style
- `declare(strict_types=1)` everywhere
- Zero runtime dependencies. Markdown rendering is in-house. Slug
  transliteration uses `ext-intl` when present (Cyrillic, Greek and other
  scripts romanise correctly). Without `ext-intl` non-ASCII characters are
  dropped — install it for sites that need accent-aware slugs.

## Built with Claude Code

Cloude is shaped while pair-coding with [**Claude Code**](https://claude.com/claude-code) —
Anthropic's terminal-native AI coding agent. The "one file per class, no magic,
no DSL, no annotations" rules aren't aesthetic preferences: they exist so the
agent (and you) can reason about any piece of the framework without loading a
runtime in your head.

Try it:

```bash
composer require cloude/framework
claude
```

Then ask Claude Code to scaffold a front controller with `Cloude\Bootstrap`,
expose an MCP server with `Cloude\Mcp\Server`, or migrate a routes file into
nested groups. The surface area is small enough that the agent usually nails
it in one prompt.

> **For agents:** see [`AGENTS.md`](AGENTS.md) — a tight reference card with
> the decision matrix, idioms and anti-patterns. AI tools (Claude Code,
> Cursor, Codex, …) pick it up automatically when they see the file at the
> repo root.
>
> **For Claude Code users specifically:** [`CLAUDE.md`](CLAUDE.md) is the
> "from zero to running feature" playbook — quick start, project layout,
> the standard add-a-feature workflow, common patterns, and how to brief
> Claude effectively. Copy it into your own project root once you start
> building on top of `cloude/framework`.
>
> **For design-pattern guidance:** [`PATTERNS.md`](PATTERNS.md) is the
> decision guide for picking an architecture — Transaction Script, MVC
> + Repository, or DDD layered. It maps "what your app looks like" to
> "which example to copy", with migration paths and anti-patterns.
>
> **For brand-new projects, AI-guided:** [`SETUP.md`](SETUP.md) is an
> interview script for AI coding agents. Point Claude Code (or any
> tool-capable agent) at this file and it walks you through eight
> steps — namespace, docroot, run mode (`php -S` / Docker / both),
> pattern, CSS, JS, optional modules — and scaffolds the project
> based on your answers.

## Installation

```bash
composer require cloude/framework
```

## Repository layout

```
cloude-php-workspace/
  src/                   # Framework source (PSR-4: Cloude\)
    Input.php
    Router.php
    View.php
    Str.php
    Markdown.php
    Markdown/
      Parser.php         # In-house markdown → HTML parser (no deps)
      File.php           # Disk I/O with transparent gzip
      Server.php         # HTTP serve with 304 / canonical / gzip
    Data/
      Repository.php     # Abstract base for "directory of files" repositories
      JsonRepository.php # One .json per entity, slug-addressed
      MarkdownRepository.php # One .md(.gz) per entity, slug-addressed
    Arr.php              # Array helpers with dot-notation access
    Bootstrap.php        # One-call front-controller bootstrap
    Cli.php              # Argv parsing + colored output for app/cli/ scripts
    Collection.php       # Fluent, chainable wrapper around an array
    Config.php
    EventLog.php
    Format.php           # Yaml / json / xml / markdown encode-decode dispatcher
    JsonFile.php
    JsonSchema.php       # In-house JSON Schema subset validator
    Logger.php           # File-backed logger with daily rotation
    TaskRunner.php       # CLI task runner: prefix:method dispatch over a class
    Http/
      Cache.php
      AssetUrl.php
      ErrorHandler.php
      HttpException.php   # Base: carries a status code
      NotFoundException.php  # extends HttpException, status 404
      Response.php
    Mcp/
      Server.php         # MCP (Model Context Protocol) server, HTTP transport
      JsonRpc.php        # JSON-RPC 2.0 + MCP error codes
      McpException.php
    views/               # Default 404 / 500 / 500-debug views (overridable)
  tests/                 # PHPUnit tests
  examples/              # Runnable sample apps (see examples/README.md)
    recipes/             # Cookbook snippets for sitemap, JSON-LD, ...
    contacts/            # Mini address-book: form + live search demo
  composer.json          # Package manifest (name: cloude/framework)
  phpunit.xml.dist
  .php-cs-fixer.dist.php
  LICENSE
  README.md
```

## Components

### Core

| Class | Responsibility |
|---|---|
| `Cloude\Arr` | Array helpers with dot-notation: `get/set/has/forget/pluck/only/except/dot/undot/merge` |
| `Cloude\Bootstrap` | Front-controller bootstrap: `initPaths()` defines `DOCROOT`/`APPPATH`/`BASEPATH`; `run()` wires cli-server passthrough + `ob_start` + `ErrorHandler` + view base (pulls debug/views from `Config` when omitted) |
| `Cloude\Cli` | Argv parsing + colored output for `app/cli/` scripts |
| `Cloude\Collection` | Fluent, chainable wrapper: `map/filter/reduce/pluck/keyBy/groupBy/sortBy/take/chunk/unique/sum/avg/min/max/...` |
| `Cloude\Config` | Env helpers (`env`/`boolEnv`); multi-env file loader (`configure`/`load`/`get`); typed accessors (`baseUrl`/`debug`/`path`); legacy `defineBaseUrl`/`defineDebug` |
| `Cloude\EventLog` | Fire-and-forget POST to a webhook for usage analytics |
| `Cloude\Format` | Yaml / json / xml / markdown encode-decode dispatcher (string ↔ array) |
| `Cloude\Input` | Wrapper over `$_GET`, `$_POST`, `$_SERVER`, raw body and JSON |
| `Cloude\JsonFile` | Per-request cached, atomic-write helper for JSON files |
| `Cloude\JsonSchema` | In-house JSON Schema subset validator (no external deps) |
| `Cloude\Logger` | File-backed logger with daily rotation and `debug/info/warn/error` |
| `Cloude\TaskRunner` | CLI task runner. `prefix:method` dispatch over registered callables or static class methods, with auto `list` / `help` |
| `Cloude\Router` | Router with `/{param}`, `/{param?}`, `/{param:regex}` patterns, nested route groups, and `get/post/put/patch/delete/any` helpers |
| `Cloude\Str` | String utilities: `upTo`/`truncate`/`truncateMiddle`/`words`/`after`/`afterLast`/`between`/`squish`/`mask`, `slug`/`ascii`, `camel`/`pascal`/`snake`/`kebab`, `random`/`uuid`/`hash` |
| `Cloude\View` | Plain PHP template rendering with variable extraction and HTML escape |

### `Cloude\Http\…`

| Class | Responsibility |
|---|---|
| `Cloude\Http\AssetUrl` | Versioned asset URLs (`/{mtime}/assets/...`) for cache-busting |
| `Cloude\Http\Cache` | HTTP cache headers (`ok`, `notFound`, `unavailable`) and `conditionalGet()` |
| `Cloude\Http\ErrorHandler` | Global error handler. Defaults to 503 (`Retry-After: 600`); honors `HttpException::statusCode` when thrown. HTML / JSON / `.md` / CLI / AJAX negotiation, debug mode |
| `Cloude\Http\HttpException` | Throwable carrying a status code; caught by `ErrorHandler` to render with that status |
| `Cloude\Http\NotFoundException` | `HttpException` pinned to 404; renders bundled `404.html.php` (or your override) |
| `Cloude\Http\Response` | One-call response helpers: `json`, `html`, `xml`, `markdown`, `redirect`, `notFound`, `noContent` |

### `Cloude\Data\…`

| Class | Responsibility |
|---|---|
| `Cloude\Data\Repository` | Abstract base for directory-of-files repositories. Subclasses implement `find` / `slugs` / `path`; `exists`, `findOr`, `all` and the `transform()` hook come for free |
| `Cloude\Data\JsonRepository` | One `.json` per entity. Atomic writes, per-request read cache via `JsonFile` |
| `Cloude\Data\MarkdownRepository` | One `.md` (or `.md.gz`) per entity. Read returns frontmatter + parsed HTML via `Markdown::parse` |

### `Cloude\Model\…` and `Cloude\Storage\…`

| Class | Responsibility |
|---|---|
| `Cloude\Model\Model` | Abstract Active Record. Subclass with `protected static string $table` + `$connection`. CRUD via `find` / `findBy` / `create` / `save` / `delete`. Static helpers: `table()`, `field('col')`, `as('alias')`, `ref()`, `query()` |
| `Cloude\Model\Storage\PdoStorage` | PDO-backed adapter. Driven by `Cloude\Storage\Connection` named pool |
| `Cloude\Model\Storage\JsonStorage` | One JSON file per row (collection mode) or one big array (collection storage) |
| `Cloude\Model\Storage\MarkdownStorage` | Markdown body + frontmatter as a row |
| `Cloude\Model\Storage\ArrayStorage` | In-memory rows; ideal for tests |
| `Cloude\Storage\Query` | Fluent SQL builder. SELECT / INSERT / UPDATE / DELETE + WHERE (with nested AND/OR groups) + INNER/LEFT/RIGHT/CROSS JOIN + ORDER BY + LIMIT/OFFSET + `count()` |
| `Cloude\Storage\TableRef` | Lightweight `(table, alias)` value object. Pair with `Model::as('u')` for typed joins; `__toString()` yields the quoted FROM/JOIN expression |
| `Cloude\Storage\Identifier` | SQL identifier-quoting helper. `quote()` for single names, `qualify()` for `table.column` / `table.*` / `*` |
| `Cloude\Storage\Connection` | Named PDO pool keyed off `Config::get('storage.{name}')` |
| `Cloude\Storage\Factory` | Builds a Storage adapter from a config row (dispatches on `driver`) |
| `Cloude\Storage\StorageException` | Framework-level wrapper around `\PDOException`. Public readonly `$sqlState`, `$sql`, `$bindings`. Specialised subclasses: `TableNotFoundException` (42S02/42P01), `ColumnNotFoundException` (42S22/42703), `DuplicateKeyException` (23000+1062, 23505), `IntegrityConstraintException` (23xxx), `ConnectionException` (08xxx), `SyntaxErrorException` (42000/42601) |

### `Cloude\Markdown\…`

| Class | Responsibility |
|---|---|
| `Cloude\Markdown` | Frontmatter + body parser. Body rendered via `Markdown\Parser` by default; swappable with `useParser()` |
| `Cloude\Markdown\File` | Disk I/O for markdown with transparent gzip (`.md` + `.md.gz`) |
| `Cloude\Markdown\Parser` | In-house markdown → HTML parser. No external dependency |
| `Cloude\Markdown\Server` | Serves a markdown file with 304 / canonical / gzip passthrough |

### `Cloude\Mcp\…`

| Class | Responsibility |
|---|---|
| `Cloude\Mcp\Server` | MCP server (Model Context Protocol) over HTTP / JSON-RPC 2.0, with auto `inputSchema` validation |
| `Cloude\Mcp\JsonRpc` | Constants for JSON-RPC 2.0 + MCP error codes |
| `Cloude\Mcp\McpException` | Structured-error throwable for tool / resource handlers |

## Quick start

A typical `www/index.php` looks like this:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config.php';   // defines BASE_URL, DEBUG, ...

if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;   // dev-server static-file passthrough
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: __DIR__ . '/../app/views',
);

use Cloude\Http\Response;
use Cloude\Input;
use Cloude\Router;
use Cloude\View;

$router = new Router(BASE_URL);

$router->get('/', fn () => View::render('home.html.php', ['title' => 'Hello']));

$router->get('/users/{id:\d+}', fn (array $p) => Response::json(['id' => $p['id']]));

$router->group('/api/v1', function (Router $r) {
    $r->post('/echo', fn () => Response::json(Input::json() ?? []));
});

$router->setNotFound(fn () => View::render('404.php'));
$router->dispatch();
```

`Bootstrap::run` wires up `ob_start`, the global 503 error handler, and the
view base path in one call. Drop in `app/config.php` and you have a complete
front controller in ~15 lines.

## Example projects

Ready-to-run sample apps live in [`examples/`](examples/). Each one is
fully self-contained — no cross-dependencies, no Apache or nginx
required, no `composer install` needed when running from inside this
repo. From the repository root:

```bash
php -S localhost:8000 -t examples/basic/www       # smallest skeleton
php -S localhost:8001 -t examples/contacts/www    # form + live JSON search
php -S localhost:8002 -t examples/library/www     # DDD layering
```

| Folder | What it shows |
|--------|---------------|
| [`examples/basic/`](examples/basic/) | Front-controller skeleton: routing, dynamic params, JSON echo, plain-PHP views |
| [`examples/contacts/`](examples/contacts/) | Form + `JsonSchema` validation + accent-insensitive search + `fetch()` from JS |
| [`examples/library/`](examples/library/) | DDD layering: Domain / Application / Infrastructure / Presentation |
| [`examples/recipes/`](examples/recipes/) | Standalone snippets — sitemap, JSON-LD, MCP, CLI tasks, repos |

Run via Docker without installing PHP locally — see
[`DEPLOYMENT.md`](DEPLOYMENT.md).

## Class reference

### `Cloude\Router`

```php
$router = new Router(basePath: '/api');       // optional, stripped from the URI

$router->get('/users/{id}', $handler);        // GET
$router->post('/users', $handler);            // POST
$router->any('/*', $handler);                 // any method
$router->add(['/foo', '/bar'], $handler);     // same handler, multiple routes

$router->setNotFound(fn() => ...);            // custom 404
$router->dispatch();
```

`{name}` segments are extracted into an associative array and passed as the
first handler argument.

**Pattern syntax:**

| Form | Meaning |
|---|---|
| `/{name}` | captures any non-slash segment as `$params['name']` |
| `/{name?}` | optional — the segment **and its leading `/`** can be absent |
| `/{name:regex}` | constrains the capture to `regex` (e.g. `\d+`, `[a-z]{2}`) |
| `/{name?:regex}` | optional + constrained |

Examples:

```php
$router->get('/users/{id:\d+}',     $h);   // matches /users/42, not /users/abc
$router->get('/posts/{slug?}',      $h);   // matches /posts AND /posts/hello
$router->get('/{lang:(es|en)}/...', $h);   // enforces 'es' or 'en' in URL
```

**Route groups** stack a URL prefix onto a block of routes. Nestable.

```php
$router->group('/api/v1', function (Router $r) {
    $r->get('/parties',          $list);     // → /api/v1/parties
    $r->get('/parties/{slug}',   $show);     // → /api/v1/parties/{slug}

    $r->group('/admin', function (Router $r) {
        $r->get('/stats', $stats);           // → /api/v1/admin/stats
    });
});
```

### `Cloude\Input`

```php
Input::method();            // GET, POST, ...
Input::uri();               // path without query string, no double slashes
Input::get('q');            // $_GET['q'] or null
Input::post('name');        // $_POST['name'] or null
Input::json();              // decodes JSON body into an array
Input::body();              // raw request body
Input::header('User-Agent');
Input::ip(trustProxy: false);
```

### `Cloude\View`

```php
View::setBasePath(__DIR__ . '/views');

View::render('home.html.php', ['title' => 'Hello']);   // prints
$html = View::capture('home.html.php', $vars);         // returns a string
echo View::e($text);                                   // HTML escape
```

View templates use the `.html.php` double extension by convention —
visually separates HTML-producing templates from PHP source files
(controllers, models) and improves IDE highlighting. The `render()` /
`capture()` methods don't enforce it; any `require`-able path works.

### `Cloude\Markdown`

```php
$result = Markdown::parse($markdownContent);
// => ['meta' => [...], 'html' => '...', 'description' => '...', ...]

$html = Markdown::toHtml($md);
```

Supports minimal YAML frontmatter (single-line `key: value` pairs):

```markdown
---
title: My article
description: Short summary
---

# Body...
```

The body is rendered with the in-house `Cloude\Markdown\Parser`. To swap in a
different engine (e.g. Parsedown if you want its full feature set):

```php
\Cloude\Markdown::useParser(fn (string $md) => (new \Parsedown())->text($md));
```

### `Cloude\Markdown\Parser`

Minimalist Markdown → HTML parser. Covers the editorial subset:

| Block | Inline |
|---|---|
| ATX headings (`#`–`######`) | `**bold**` / `__bold__` |
| Paragraphs | `*italic*` / `_italic_` |
| Unordered / ordered lists | `` `inline code` `` |
| Fenced code blocks (` ``` `) | `[link](url "title")` |
| Blockquotes (`>`) | `![img](src "title")` |
| Horizontal rules (`---`, `***`) | Hard line break (`  \n` or `\\\n`) |
| GFM tables with alignment | (inline parser runs on cell contents) |

```markdown
| Type | Bore | License |
|---|:---:|---:|
| Shotgun | smooth | E |
| Rifle   | rifled | D |
```

Alignment colons in the separator row: `|:---|` left, `|:---:|` center,
`|---:|` right. Without the separator the rows fall back to a paragraph
(matches Parsedown). Inline markdown inside cells works: `**bold**`,
`` `code` ``, `[link](url)`, `*italic*`, images.

Not supported (by design): footnotes, definition lists, reference-style
links, setext headings, nested lists. If you need any of these, plug
Parsedown in via `Markdown::useParser()`.

```php
$html = \Cloude\Markdown\Parser::toHtml("# Hello\n\nFirst **paragraph**.");
```

### `Cloude\Str`

```php
// Basic manipulation
Str::upTo('hello world', ' ');           // 'hello'
Str::truncate('long text', 4);           // 'long...'
Str::truncateMiddle('/var/log/app/very/deep/path/file.log', 25);
                                          // '/var/log/app...h/file.log' (paths, hashes, breadcrumbs)
Str::words('a b c d e', 3);              // 'a b c...'
Str::after('foo.bar.baz', '.');          // 'bar.baz'
Str::afterLast('foo.bar.baz', '.');      // 'baz'
Str::between('hi [there] go', '[', ']'); // 'there'
Str::squish("  a\n  b\t  c ");           // 'a b c'
Str::mask('pedro@example.com', '*', 2);  // 'pe***************'
Str::mask('+34600123456', '*', 4, -3);   // '+346*****456'

// Slugs / transliteration
Str::slug('Hello World');                // 'hello-world'
Str::ascii('Análisis Político');         // 'Analisis Politico'
Str::ascii('Москва');                    // 'Moskva'

// Case conversion
Str::camel('user_profile_id');           // 'userProfileId'
Str::pascal('user_profile_id');          // 'UserProfileId'
Str::snake('userProfileId');             // 'user_profile_id'
Str::kebab('userProfileId');             // 'user-profile-id'

// Random / hash
Str::random();                           // 22-char URL-safe token
Str::random(32);                         // 43-char URL-safe token
Str::uuid();                             // RFC 4122 v4
Str::hash('hola');                       // sha256 hex
Str::hash('hola', 'sha1');               // any hash_algos() entry
```

`ascii()` transliterates without lowercasing or stripping punctuation —
useful for fuzzy matching and search indexes. For URL slugs use `slug()`.
`random()` and `uuid()` use `random_bytes` (cryptographically strong).

### `Cloude\Arr`

Array helpers with dot-notation access. The handful of methods you actually
reach for when working with deeply-nested PHP arrays.

```php
use Cloude\Arr;

Arr::get($cfg, 'db.read.host', 'localhost');     // dot-path with default
Arr::set($cfg, 'db.read.port', 5432);            // creates intermediates
Arr::has($cfg, 'db.read');                       // bool
Arr::forget($cfg, 'db.read.port');               // unset

// Working with row lists
Arr::pluck($users, 'name');                      // [0 => 'Ana', 1 => 'Bea']
Arr::pluck($users, 'name', 'id');                // [42 => 'Ana', 51 => 'Bea']
Arr::pluck($events, 'meta.title');               // dot-path supported

// Subset / inverse
Arr::only($row, ['id', 'name']);
Arr::except($row, ['password', 'salt']);

// Flatten / inflate
Arr::dot(['a' => ['b' => 1]]);                   // ['a.b' => 1]
Arr::undot(['a.b' => 1]);                        // ['a' => ['b' => 1]]

// Recursive merge — later overrides earlier; lists replaced wholesale
Arr::merge(['db' => ['host' => 'a', 'port' => 1]], ['db' => ['port' => 2]]);
// => ['db' => ['host' => 'a', 'port' => 2]]
```

Convention: associative arrays are walked recursively, list arrays
(numeric `0..n` keys) are treated as leaves — matching how JSON decodes
objects vs arrays.

### `Cloude\Collection`

Fluent, chainable wrapper around an array — the small set of pipeline
operations (`map`, `filter`, `pluck`, `groupBy`, `sortBy`, …) that turn
data shuffling from nested loops into a one-line read.

```php
use Cloude\Collection;

$top = Collection::make($users)
    ->filter(fn ($u) => $u['active'])
    ->sortBy('score', descending: true)
    ->take(3)
    ->pluck('name', 'id')
    ->all();
```

**Terminals** (return scalars / arrays):

```php
$c->all();                    // array
$c->count();                  // int
$c->isEmpty(); $c->isNotEmpty();
$c->first(); $c->first(fn ($v) => ...);
$c->last();
$c->contains($value);
$c->every(callable);  $c->some(callable);
$c->reduce(callable, $initial);
$c->sum('column'); $c->avg('column'); $c->min('col'); $c->max('col');
```

**Chainable** (return a new Collection):

```php
$c->map(fn ($v, $k) => ...);
$c->filter(?callable);  $c->reject(callable);
$c->each(callable);                        // returns $this; false stops
$c->pluck('column', 'key');                // dot-paths supported
$c->keyBy('column' | callable);
$c->groupBy('column' | callable);
$c->sortBy('column' | callable, descending: false);
$c->sort(?callable);
$c->reverse();
$c->take(int);  $c->slice($offset, $length);
$c->chunk(int);
$c->unique(?'column');
$c->values();  $c->keys();
$c->merge(...$others);
```

`Collection` implements `ArrayAccess`, `Countable` and is iterable, so
`$c[0]`, `count($c)` and `foreach ($c as ...)` all work as expected.
Use `make()` to construct from arrays, generators, or other collections.

### `Cloude\Data\Repository` and friends

A "directory of files" data layer for the typical Cloude project: every
entity is one file (`.json` or `.md`), addressed by slug. The framework
ships an abstract base plus two ready-to-use concretes; projects subclass
the concrete that fits their format and add domain finders on top.

```php
class PartiesRepo extends \Cloude\Data\JsonRepository
{
    public function __construct(string $country)
    {
        parent::__construct(DATA_DIR . "/scopes/{$country}/parties");
    }

    /** Normalize each row into a stable shape, slug included. */
    protected function transform(array $data, string $slug): array
    {
        return [
            'slug'   => $slug,
            'name'   => $data['nombre']             ?? $slug,
            'family' => $data['familia_ideologica'] ?? null,
        ] + $data;
    }

    public function byFamily(string $family): \Cloude\Collection
    {
        return $this->all()->filter(fn ($p) => $p['family'] === $family);
    }
}

$parties = new PartiesRepo('espana');

$parties->slugs();                     // ['psoe', 'pp', 'vox', ...]
$parties->exists('psoe');              // bool
$parties->find('psoe');                // ?array (null if missing)
$parties->findOr('psoe', []);          // array (default if missing)
$parties->all();                       // Cloude\Collection keyed by slug
$parties->byFamily('left');            // Cloude\Collection
$parties->write('new', [...]);         // atomic write (.json)
```

**Inherited from `Repository`:**
`exists`, `findOr`, `all`, and the `transform($data, $slug)` hook for
shape normalization. The default `transform()` attaches `_slug` so that
`pluck`/`groupBy` results keep the slug accessible.

**Implementations provided:**

| Class | Path | Read | Write |
|---|---|---|---|
| `JsonRepository` | `{baseDir}/{slug}.json` | `JsonFile::read` (per-request cache) | `JsonFile::write` (atomic) |
| `MarkdownRepository` | `{baseDir}/{slug}.md` (also reads `.md.gz`) | `Markdown::parse` (frontmatter + body) | `Markdown\File::write` (gzipped) |

`MarkdownRepository::raw($slug)` returns the unparsed markdown source if
you want to serve it verbatim. Both classes accept a custom file
extension via the constructor; override `path()` to change the layout
entirely (e.g. sharded sub-directories).

`all()` returns a `Cloude\Collection`, so once you have a Repository
the chainable pipeline is one method call away — see the
[recipe](examples/recipes/data.php) for the full pattern.

### `Cloude\Model\Model` — static table / field / alias helpers

Every subclass exposes four static helpers built on top of `$table`:

| Call                  | Returns                          | Used for                                            |
|-----------------------|----------------------------------|-----------------------------------------------------|
| `User::table()`       | `'users'`                        | Raw SQL, FROM clauses                               |
| `User::field('email')`| `'users.email'`                  | Qualified column references in `select` / `where` / `join` |
| `User::ref()`         | `TableRef('users', null)`        | Pass as `from()` / `join()` target without aliasing |
| `User::as('u')`       | `TableRef('users', 'u')`         | Aliased FROM / JOIN — call `$u->field('email')` for `'u.email'` |

Use them instead of hand-writing string literals — refactoring a table
name then changes one constant and the rest follows.

### `Cloude\Storage\Query` — SQL builder

```php
$q = User::query();              // shorthand
$q = new Query($pdo, 'users');   // direct
$q = new Query($pdo, User::as('u'));   // aliased
```

**SELECT.** Fluent, immutable-ish (one `Query` instance per call site):

```php
$q->where('active', 1)
  ->where('age', '>', 18)
  ->orderBy('name')
  ->limit(10)
  ->get();                                // list<array<string,mixed>>

$q->where('email', 'a@b.com')->first();   // ?array
$q->where('active', 1)->count();          // int
$q->select('email')->pluck('email');      // list<string>
$q->select('id', 'name')->pluck('name', 'id');   // [id => name, ...]
$q->select('name')->where(...)->value('name');   // first scalar
```

**WHERE variants.**

```php
$q->where('age', '>', 18);
$q->where('email', 'a@b.com');           // shorthand → '='
$q->where('age', '=', null);             // auto-rewritten as IS NULL
$q->whereIn('id', [1, 2, 3]);
$q->whereNotIn('id', [...]);
$q->whereNull('deleted_at');
$q->whereNotNull('email');
$q->whereBetween('age', 18, 65);
$q->orWhere('role', 'admin');            // OR-joined to previous predicate
```

Allowed operators: `= != <> < <= > >= LIKE NOT LIKE IN NOT IN BETWEEN
NOT BETWEEN IS NULL IS NOT NULL`. Anything else throws.

**Nested AND/OR groups.** When you want mixed predicates with explicit
parentheses, pass a closure:

```php
$q->where('active', 1)
  ->whereGroup(fn ($g) =>
      $g->where('role', 'admin')->orWhere('role', 'editor'))
  ->orWhereGroup(fn ($g) =>
      $g->where('country', 'ES')->where('vip', 1));

// → WHERE active = 1
//      AND (role = 'admin' OR role = 'editor')
//      OR  (country = 'ES' AND vip = 1)
```

The closure receives a fresh `Query` whose WHEREs are spliced into the
parent's clause list as one parenthesised block. Empty groups are no-ops.

**JOINs.** `join` / `leftJoin` / `rightJoin` take the joined table plus a
column-vs-column ON condition. `crossJoin` takes only the table.

```php
$rows = User::query()
    ->select('users.name', 'orders.total')
    ->leftJoin('orders', 'orders.user_id', '=', 'users.id')
    ->where('orders.status', 'paid')
    ->orderBy('users.name')
    ->get();
```

Columns can be qualified strings (`'users.name'`) — the builder quotes
them automatically via `Identifier::qualify()`. For aliased joins, pair
`Model::as()` with `from()`:

```php
$u = User::as('u');
$o = Order::as('o');

$rows = User::query()->from($u)
    ->select($u->field('name'), $o->field('total'))
    ->join($o, $o->field('user_id'), '=', $u->field('id'))
    ->where($o->field('status'), 'paid')
    ->get();

// SELECT `u`.`name`, `o`.`total`
// FROM `users` AS `u`
// JOIN `orders` AS `o` ON `o`.`user_id` = `u`.`id`
// WHERE `o`.`status` = ?
```

The ON operator must be a comparison (`= != <> < <= > >=`) — joins never
bind values. For multi-condition ON clauses (`ON a = b AND c = d`), add
the extra predicate as a `where()` instead.

**INSERT / UPDATE / DELETE.**

```php
$id    = $q->insert(['name' => 'Ada', 'email' => 'a@x']);   // last-insert id
$count = $q->where('age', '<', 18)->update(['active' => 0]); // affected rows
$count = $q->where('active', 0)->delete();
```

Mutations honour the current `where()` chain. `UPDATE` / `DELETE` accept
`orderBy()` / `limit()` too, but those are MySQL/SQLite extensions —
Postgres throws on execute. Joins are SELECT-only.

**Debugging.** `compile()` returns the SELECT SQL with bindings inlined
as SQL literals — paste it into a SQL client to reproduce the query.
**Never** feed `compile()` output back through `PDO::exec()`: prepared
statements remain the only safe execution path.

```php
echo $q->where('age', '>', 18)->compile();
// SELECT * FROM `users` WHERE `age` > 18
```

**Not in scope:** UNIONs, subqueries, window functions, CTEs, `GROUP BY`
/ `HAVING`, aggregations beyond `count()`. Drop to PDO via
`$q->pdo()` (or `Connection::pdo('default')`) and write the SQL.

### `Cloude\Storage\StorageException` — catchable SQL errors

Every Query / PdoStorage execution that hits PDO is wrapped: a raw
`\PDOException` never leaks past the storage layer. The wrapper is a
plain `\RuntimeException` subclass, so an uncaught one renders as the
usual 503 via `ErrorHandler`. Catch it (or a specialised subclass) to
turn driver-specific SQLSTATEs into application behaviour:

```php
use Cloude\Storage\{StorageException, DuplicateKeyException, TableNotFoundException};

try {
    User::create(['email' => $email, 'name' => $name]);
} catch (DuplicateKeyException $e) {
    return Response::json(['error' => 'email_taken'], 409);
} catch (TableNotFoundException $e) {
    // Probably a missing migration.
    Logger::error("missing table", ['sql' => $e->sql]);
    throw $e;
} catch (StorageException $e) {
    // Anything else SQL-related — log structured fields, re-throw or
    // render. $e->getPrevious() is the original PDOException.
    Logger::error('db', [
        'sqlstate' => $e->sqlState,
        'sql'      => $e->sql,
        'bindings' => $e->bindings,
        'message'  => $e->getMessage(),
    ]);
    throw $e;
}
```

The dispatch table (`StorageException::wrap()`) covers the SQLSTATEs you
hit in practice:

| SQLSTATE / code              | Subclass                          | Typical cause                                  |
|------------------------------|-----------------------------------|------------------------------------------------|
| 42S02 (MySQL/SQLite), 42P01 (PG) | `TableNotFoundException`        | Missing migration / typo in `$table`           |
| 42S22, 42703                 | `ColumnNotFoundException`         | Typo / stale schema                            |
| 23000 + driver code 1062 (MySQL), 23505 (PG) | `DuplicateKeyException`     | UNIQUE / PRIMARY KEY collision                 |
| 23xxx (everything else)      | `IntegrityConstraintException`    | FK / NOT NULL / CHECK violation                |
| 08xxx                        | `ConnectionException`             | Can't reach the DB                             |
| 42000, 42601                 | `SyntaxErrorException`            | Builder bug or hand-written SQL                |
| anything else                | `StorageException`                | Unmapped — base class still carries the fields |

Public readonly fields on every instance:

| Field        | Type                | Notes                                                       |
|--------------|---------------------|-------------------------------------------------------------|
| `$sqlState`  | `string`            | The five-character SQLSTATE                                 |
| `$sql`       | `string`            | The SQL that failed (with `?` placeholders)                 |
| `$bindings`  | `list<mixed>`       | Bind values in order — may carry secrets; never echo        |
| `getPrevious()` | `\PDOException`  | Original driver exception (`errorInfo` preserved)           |

### `Cloude\Bootstrap`

Folds the canonical front-controller boilerplate into two calls:
**(1)** `initPaths()` defines the three directory constants every
project uses (`DOCROOT`, `APPPATH`, `BASEPATH`), and **(2)** `run()`
wires `ob_start()`, `ErrorHandler::register()` and
`View::setBasePath()` in one go.

```php
require __DIR__ . '/../vendor/autoload.php';

\Cloude\Bootstrap::initPaths(
    docroot: __DIR__,
    apppath: dirname(__DIR__) . '/app',
    // basepath: defaults to dirname(apppath) if omitted
);
\Cloude\Config::configure(APPPATH . '/config');

if (\Cloude\Bootstrap::serveStaticIfExists(DOCROOT)) {
    return false;
}

\Cloude\Bootstrap::run();   // reads debug + views from Config

$router = new \Cloude\Router(\Cloude\Config::baseUrl(['example.com', 'localhost']));
// ...routes...
$router->dispatch();
```

**The framework's directory model.** Only three constants — defined
by `initPaths()`. Every other knob (data dir, view dir, base URL,
debug flag, db / mail / cache options) goes through `Cloude\Config`
files under `APPPATH/config/`. The legacy global-constants style
(`defineBaseUrl()`, `defineDebug()`, ad-hoc `DATA_DIR`) still works
unchanged, but new projects should prefer the Config-driven approach.

| Constant   | Purpose                          | Set by `initPaths()` from |
|------------|----------------------------------|---------------------------|
| `DOCROOT`  | Public web root (`www/`)         | `$docroot` arg            |
| `APPPATH`  | Application root (`app/`)        | `$apppath` arg            |
| `BASEPATH` | Project root (parent of both)    | `$basepath` arg, or `dirname($apppath)` |

`initPaths()` is idempotent — pre-defined constants are left alone,
so tests can pin any of them up front.

`Bootstrap::run()` arguments are optional in v0.35+: when omitted
`$debug` comes from `Config::debug()` and `$viewBase` from
`Config::path('views')`. Pass them explicitly to override.

`serveStaticIfExists()` returns `true` only under the PHP built-in dev
server when the request hits a real file inside `$docroot`. The caller
MUST `return false;` from the router script in that case (cli-server's
documented convention to delegate to its static handler). Production
Apache uses `.htaccess`, so this is a no-op there.

### `Cloude\Http\Response`

One-call response helpers — set status + Content-Type + body, then return.

```php
use Cloude\Http\Response;

Response::json(['ok' => true]);                  // 200 + application/json
Response::json(['error' => 'nope'], 422);
Response::html('<h1>Hi</h1>');
Response::xml('<?xml version="1.0"?><root/>');
Response::markdown("# Title\n\nBody.");
Response::redirect('/login', 302);
Response::notFound('# 404', 'text/markdown');
Response::noContent();                            // 204
```

JSON is encoded with `UNESCAPED_UNICODE | UNESCAPED_SLASHES`. Pass
`pretty: true` for indented output. `redirect()` strips CRLF from the URL to
prevent header injection.

### `Cloude\Config`

Two responsibilities in one class: **(1)** env-var helpers and the
typed bootstrap accessors (`baseUrl`, `debug`, `path`), **(2)** the
multi-environment config-file loader (FuelPHP-style — base files +
per-env overrides deep-merged).

The framework only ships three directory constants (defined by
[`Bootstrap::initPaths()`](#cloudebootstrap) — `DOCROOT`, `APPPATH`,
`BASEPATH`); everything else lives in config files and flows through
`Config::get()` / `Config::baseUrl()` / `Config::debug()` / `Config::path()`.

```php
// Env-var helpers (always available)
Config::env('OPENAI_API_KEY');           // ?string — empty string treated as missing
Config::boolEnv('DEBUG', false);          // bool — 1/true/yes/on (case-insensitive)

// Wire the loader (once, in www/index.php right after initPaths)
Config::configure(APPPATH . '/config');
// optional: ::configure($path, environment: 'prod')
// otherwise APP_ENV / ENVIRONMENT env vars decide

// Read a file/dot-path
Config::get('db.default.dsn');            // any value
Config::load('db');                       // whole merged array
Config::environment();                    // current env name ('dev' default)

// Typed accessors — the recommended way to read framework knobs
Config::baseUrl(['example.com']);         // memoized; reads app.base_url, then env, then auto-detect
Config::debug();                          // bool — reads app.debug, then env DEBUG
Config::path('data');                     // app.paths.data
Config::path('cache', '/tmp/c');          // with fallback

// Legacy global-constants helpers (still supported, back-compat)
Config::defineBaseUrl(['example.com']);   // → define('BASE_URL', ...)
Config::defineDebug();                    // → define('DEBUG', ...)
```

**Directory layout under `APPPATH/config/`:**

```
app/config/
├── app.php          # base — always loaded
├── db.php
├── mail.php
├── dev/             # active when environment is 'dev'
│   └── app.php      # deep-merged onto the base
└── prod/
    ├── app.php
    └── db.php
```

**Conventional `app/config/app.php`:**

```php
return [
    'base_url' => Cloude\Config::env('BASE_URL'),    // null → auto-detect
    'debug'    => Cloude\Config::boolEnv('DEBUG'),
    'paths' => [
        'data'  => BASEPATH . '/data',
        'views' => APPPATH . '/views',
    ],
];
```

`baseUrl()` resolves in this order: `BASE_URL` global constant (if
already defined), `app.base_url` config, `BASE_URL` env var,
auto-detected scheme + Host (validated against the optional allowlist;
non-allowed hosts collapse to `localhost` to prevent header injection).
The result is memoized for the request — `Config::reset()` clears it.

### `Cloude\Http\ErrorHandler`

Drop-in global handler. Defaults unhandled errors to **503** (temporary
unavailability — crawlers retry instead of deindexing the URL). Throw a
`Cloude\Http\HttpException` (or its `NotFoundException` subclass) to
override the status — see [`Cloude\Http\HttpException`](#cloudehttphttpexception--notfoundexception)
below.

Negotiates the response format from the request context:

| Request looks like…                                                | Response          |
|---------------------------------------------------------------------|-------------------|
| `PHP_SAPI === 'cli'`                                                | plain text on STDERR |
| `Accept: application/json` *or* `Content-Type: application/json`    | JSON              |
| `X-Requested-With: XMLHttpRequest` (classic jQuery AJAX)            | JSON              |
| URL path ends in `.json`                                            | JSON              |
| URL path ends in `.md`                                              | text/plain (markdown) |
| Everything else                                                     | HTML view         |

```php
ob_start();
\Cloude\Http\ErrorHandler::register(
    debug:    DEBUG,
    viewBase: dirname(__DIR__) . '/app/views', // optional override directory
);
```

In debug mode, HTML responses include source snippet and stack trace
(`500-debug.html.php`, shared by every status). In production, the HTML
template is chosen by status: 404 → `404.html.php`, anything else →
`500.html.php`. If `viewBase` is set and the file exists there, it
overrides the framework default — drop a `404.html.php` next to your
other views to brand the page.

The content-negotiation logic is exposed as `ErrorHandler::negotiate($server)`
(pure function over a `$_SERVER`-shaped array, returns `'json' | 'md' | 'html'`)
so you can unit-test consumers without touching the global state.

### `Cloude\Http\HttpException` + `NotFoundException`

Throw one of these from anywhere in a request handler and `ErrorHandler`
renders with the carried status code (instead of falling back to 503).
Headers and templates follow the status:

- **404** — bundled `404.html.php` view (no `Retry-After`)
- **other** — falls back to the `500.html.php` template
- **503** — adds `Retry-After: 600` and uses `500.html.php`

```php
use Cloude\Http\HttpException;
use Cloude\Http\NotFoundException;

// Idiomatic 404 from a controller:
$book = $repo->find($isbn) ?? throw new NotFoundException("book $isbn");

// Any HTTP status — message goes into the JSON / debug output:
throw new HttpException(403, 'forbidden');
```

`HttpException` is the base class with a `public readonly int $statusCode`.
`NotFoundException` is just `HttpException` with the code pinned to 404 and a
default message of `'Not found'`. No other subclasses ship — make your own
(`class ForbiddenException extends HttpException { public function __construct(string $m = 'Forbidden') { parent::__construct(403, $m); } }`)
if a project wants named 401 / 403 / 422 helpers.

JSON responses for `HttpException`s look like:

```json
{"error": "not_found", "status": 404}
```

In debug they include `message`, `file`, `line`, `trace`, and `status`.
CLI prints `"Not found."` for 404, `"Error: service temporarily unavailable."` otherwise.

### `Cloude\Http\Cache`

```php
Cache::ok();                          // long CDN TTL on 200
Cache::notFound();                    // short CDN TTL on 404
Cache::unavailable();                 // no-store + Retry-After on 5xx

if (Cache::conditionalGet(filemtime($path))) {
    return; // 304 sent
}
```

### `Cloude\Http\AssetUrl`

```php
AssetUrl::configure(BASE_URL, __DIR__ . '/../www/assets');
echo AssetUrl::get('css/styles.css');
// → "{BASE_URL}/{mtime}/assets/css/styles.css"
```

Apache rewrite required:

```apacheconf
RewriteRule ^[0-9]+/assets/(.*)$ /assets/$1 [L]
```

### `Cloude\Markdown\File`

Disk I/O for markdown with transparent gzip. Pass plain `.md` paths;
the class prefers `.md.gz` if it exists.

```php
use Cloude\Markdown\File;

File::exists($path);                   // bool — .md or .md.gz
File::read($path);                     // string — auto-decompressed
File::readPrefix($path, 4096);         // first N bytes (for frontmatter)
File::mtime($path);                    // int
File::write($path, $content);          // writes .md.gz, removes .md
```

### `Cloude\Markdown\Server`

Serves a markdown file with proper HTTP semantics (404 / 304 / canonical /
gzip passthrough when the client supports it).

```php
\Cloude\Markdown\Server::serve($path, BASE_URL . '/articles/foo');
```

### `Cloude\Format`

One-stop conversion between strings and PHP arrays. Each top-level method
dispatches by input type — string is decoded, array is encoded.

```php
use Cloude\Format;

// JSON
Format::json('{"a":1}');                   // ['a' => 1]
Format::json(['a' => 1]);                  // '{"a":1}'
Format::json(['a' => 1], pretty: true);    // pretty-printed

// YAML (flat key:value, frontmatter-compatible)
Format::yaml("title: Hi\nflag: true");     // ['title' => 'Hi', 'flag' => true]
Format::yaml(['title' => 'Hi']);           // "title: Hi\n"

// XML — keys with '@' become attributes, '#text' is text content,
// list arrays repeat the element. See examples/recipes/sitemap.php.
Format::xml(['urlset' => [
    '@xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9',
    'url'    => [['loc' => 'a'], ['loc' => 'b']],
]], pretty: true);

// Markdown → HTML
Format::markdown('# Hello **world**');     // "<h1>Hello <strong>world</strong></h1>\n"
```

Explicit helpers when you want a fixed return type or DI-friendly call:
`Format::jsonDecode`, `Format::jsonEncode`, `Format::yamlDecode`,
`Format::yamlEncode`, `Format::xmlDecode`, `Format::xmlEncode`. JSON helpers
throw `\JsonException` on errors. YAML encoding throws
`\InvalidArgumentException` for nested arrays or non-identifier keys (YAML
support is intentionally minimal — for nested data use JSON or XML).

`Cloude\Markdown::parse` (frontmatter + body) and `Cloude\JsonFile`
delegate to `Format` internally, so behaviour stays consistent.

### `Cloude\JsonFile`

Per-request cached, atomic-write helper for JSON files.

```php
use Cloude\JsonFile;

JsonFile::read($path);                 // ?array — cached, null if missing/invalid
JsonFile::readOr($path, []);           // array — never null
JsonFile::write($path, $data);         // atomic (temp + rename); UNESCAPED_UNICODE | UNESCAPED_SLASHES
JsonFile::write($path, $data, true);   // pretty-print
JsonFile::clearCache();                // clear all, or pass a path
```

### `Cloude\EventLog`

Fire-and-forget webhook POST for usage analytics. Reads from `EVENT_LOG_WEBHOOK`
constant if defined, or from `EventLog::configure()`.

```php
EventLog::configure('https://webhook.site/<uuid>');
EventLog::send(['event' => 'page_view', 'path' => '/foo']);
```

Network call is deferred to `register_shutdown_function` and uses
`fastcgi_finish_request()` when available — zero latency to the user.

### `Cloude\JsonSchema`

Pragmatic, dependency-free JSON Schema validator. Covers the subset that
matters for MCP tool inputs, REST request bodies, and config validation:
`type`, `required`, `properties`, `additionalProperties`, `enum`, `items`,
`minItems`/`maxItems`, `minimum`/`maximum`, `minLength`/`maxLength`, `pattern`.

```php
$schema = [
    'type' => 'object',
    'properties' => [
        'country' => ['type' => 'string', 'pattern' => '^[a-z]{2}$'],
        'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
    ],
    'required' => ['country'],
    'additionalProperties' => false,
];

$errors = \Cloude\JsonSchema::validate($args, $schema);
if ($errors !== []) {
    throw new \InvalidArgumentException(implode('; ', $errors));
}

\Cloude\JsonSchema::isValid($args, $schema);   // bool shortcut
```

Errors are human-readable strings prefixed with the JSON pointer of the
offending node — e.g. `$.country: value does not match pattern '^[a-z]{2}$'`.

Out of scope (and probably forever): `$ref`, `allOf`/`oneOf`/`anyOf`, `not`,
`if`/`then`/`else`, `format`, `patternProperties`, schema meta-validation.
If you need any of those, install `opis/json-schema` and use it directly.

### `Cloude\Mcp\Server`

A minimal MCP (Model Context Protocol) server: HTTP transport, JSON-RPC 2.0,
input validation auto-wired against each tool's `inputSchema` via
`Cloude\JsonSchema`.

```php
use Cloude\Mcp\JsonRpc;
use Cloude\Mcp\McpException;
use Cloude\Mcp\Server;

$mcp = new Server(
    name:        'my-data',
    version:     '1.0',
    description: 'Public dataset.',
    endpoint:    BASE_URL . '/mcp',
);

$mcp->tool(
    name:        'echo',
    description: 'Echoes the message.',
    inputSchema: [
        'type'       => 'object',
        'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
        'required'   => ['message'],
    ],
    handler: function (array $args): array {
        if ($args['message'] === 'forbidden') {
            // Structured errors → JSON-RPC error response with the right code.
            throw new McpException(JsonRpc::INVALID_PARAMS, 'forbidden message');
        }
        return ['content' => [['type' => 'text', 'text' => $args['message']]]];
    },
);

// Optional resource provider + reader.
$mcp->resourceProvider(fn() => [['uri' => 'mem://hi', 'name' => 'Hi', 'mimeType' => 'text/plain']]);
$mcp->resourceReader(fn($uri) => $uri === 'mem://hi'
    ? ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => 'world']
    : null);

// Wire up routes.
$router->get('/.well-known/mcp.json', fn () => $mcp->respondManifest());
$router->any(['/mcp', '/mcp-server'], fn () => $mcp->dispatch());
```

What it handles for you:

- CORS headers + `OPTIONS` preflight (204).
- JSON-RPC parse + dispatch with the right error codes (`-32700`,
  `-32600`, `-32601`, `-32602`, `-32603`, `-32002`).
- Standard methods with sane defaults: `initialize`, `ping`,
  `notifications/initialized`, `notifications/cancelled`, `prompts/list`,
  `prompts/get`, `resources/list`, `resources/read`,
  `resources/templates/list`, `logging/setLevel`, `tools/list`, `tools/call`.
- `tools/call` validates `arguments` against the tool's `inputSchema` before
  the handler runs — bad input becomes a `-32602` response.
- `/.well-known/mcp.json` discovery manifest auto-generated from registered
  capabilities.

Out of scope: stdio transport, SSE/streaming, auth (do that in a route
middleware before calling `dispatch()`).

See [`examples/recipes/mcp.php`](examples/recipes/mcp.php) for a runnable server.

### `Cloude\Cli`

Tiny helper for scripts under `app/cli/`: argv parsing + colored output
with TTY detection.

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../../vendor/autoload.php';

use Cloude\Cli;

$args   = Cli::parseArgs($argv);          // ['_' => [...], 'dry-run' => true, 'limit' => '100', ...]
$dryRun = Cli::flag($args, 'dry-run');    // bool
$limit  = (int) (Cli::option($args, 'limit') ?? 100);
$path   = Cli::positional($args, 0);      // first non-flag argument

Cli::info("processing $limit items" . ($dryRun ? ' (dry run)' : ''));
if ($errors > 0) {
    Cli::abort(1, "$errors items failed");
}
Cli::success('done');
```

The `--` token stops flag parsing — anything after goes to positional. Colors
are emitted only when STDOUT is a TTY, so piping to a file produces clean
plain text.

### `Cloude\Logger`

File-backed logger with daily rotation.

```php
$log = new \Cloude\Logger('/var/log/myapp.log', minLevel: 'info');
$log->info('http request', ['path' => '/foo']);
$log->error('db unreachable', ['code' => 503]);
// → /var/log/myapp-2026-05-07.log:
//   [2026-05-07T08:30:12Z] [INFO] http request {"path":"/foo"}
//   [2026-05-07T08:30:12Z] [ERROR] db unreachable {"code":503}
```

Levels: `debug` < `info` < `warn` < `error`. Messages below `minLevel` are
dropped. Pass `rotation: 'none'` to disable rotation and always write to the
configured path. Context arrays are appended as compact JSON.

### `Cloude\TaskRunner`

Tiny CLI task runner — Artisan / Rake without the framework. Drop a single
entry-point script (`app/cli/tasks.php`), register callables or static class
methods, and you get `list` / `help` / dispatch for free.

```php
use Cloude\Cli;
use Cloude\TaskRunner;

class ContentTasks
{
    /** Rebuild the search index for $country (default es). */
    public static function rebuildIndex(array $args): int
    {
        $country = Cli::option($args, 'country', 'es');
        $dryRun  = Cli::flag($args, 'dry-run');
        Cli::info("rebuilding index for {$country}" . ($dryRun ? ' (dry run)' : ''));
        return 0;
    }

    /** Drop content older than N days (default 90). */
    public static function purgeOld(array $args): int
    {
        $days = (int) (Cli::option($args, 'days') ?? 90);
        Cli::info("purging content older than {$days} days");
        return 0;
    }
}

$runner = new TaskRunner();
$runner->register('ping', fn () => Cli::out('pong'), 'Connectivity check.');
$runner->registerClass('content', ContentTasks::class);

exit($runner->run($argv));
```

Then from the shell:

```bash
php app/cli/tasks.php                                  # list every task
php app/cli/tasks.php help content:rebuild-index       # describe one
php app/cli/tasks.php content:rebuild-index --country=fr --dry-run
php app/cli/tasks.php content:purge-old --days=30
```

`registerClass()` walks the public-static methods of a class. Method names
are kebab-cased (`rebuildIndex` → `rebuild-index`), and the first non-blank
line of each method's docblock becomes the description shown in `list`.
Inherited methods are skipped, so you can compose multiple task classes
without surprises.

Each handler receives the parsed `$args` array (`Cli::parseArgs($argv)`) and
may return `int` (exit code), `null`/`true`/`void` (exit 0), or `false`
(exit 1). Uncaught exceptions are reported via `Cli::error()` and the runner
exits 1.

See [`examples/recipes/tasks.php`](examples/recipes/tasks.php) for a runnable
template.

## Recipes (cookbook snippets)

`examples/recipes/` ships drop-in snippets for common patterns the framework
deliberately doesn't wrap in a class:

| Recipe | What it does |
|---|---|
| [`sitemap.php`](examples/recipes/sitemap.php) | XML sitemap (and sitemap index) using `Format::xml` + `Http\Response::xml` |
| [`jsonld.php`](examples/recipes/jsonld.php) | Schema.org JSON-LD blocks (Article, BreadcrumbList, FAQPage) using `Format::json` |
| [`mcp.php`](examples/recipes/mcp.php) | Tiny MCP server with two tools and a resource catalogue using `Mcp\Server` |
| [`tasks.php`](examples/recipes/tasks.php) | CLI task runner with one inline task and one task class using `TaskRunner` |
| [`data.php`](examples/recipes/data.php) | Custom `JsonRepository` and `MarkdownRepository` subclasses with `transform()` and domain finders |

Each recipe is a single self-contained file with comments — copy, paste, edit.

## Development

```bash
composer install
composer test        # phpunit
composer cs-check    # php-cs-fixer in dry-run mode
composer cs-fix      # apply fixes
```

## Publishing to Packagist

1. Push this repository to GitHub as a public repo.
2. Submit the repository URL at <https://packagist.org/packages/submit>.
3. (Recommended) Configure the GitHub -> Packagist webhook so new tags are picked up automatically.
4. Tag a release:

   ```bash
   git tag -a v0.15.0 -m "v0.15.0"
   git push origin v0.15.0
   ```

After publication, any project can install it with:

```bash
composer require cloude/framework
```

## Philosophy

- **No magic**: the code you read is the code that runs. No generators, annotations or proxies.
- **Small classes**: each class fits in a file you can read in one sitting — and so can [Claude Code](https://claude.com/claude-code).
- **No required dependencies**: the core pulls nothing in. `ext-intl` is recommended for slug transliteration but not required.
- **No global state**: no container, no singletons. Static classes are just namespaces for functions.
- **AI-readable by design**: explicit APIs, no DSL, no inheritance webs. The framework is small on purpose so an agent can edit any piece without missing context.

## License

MIT - see [LICENSE](LICENSE).
