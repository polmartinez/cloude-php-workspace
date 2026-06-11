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
  tests/                 # Cloude\Testing — run with `vendor/bin/cloude-test`
  examples/              # Runnable sample apps (see examples/README.md)
    recipes/             # Cookbook snippets for sitemap, JSON-LD, ...
    contacts/            # Mini address-book: form + live search demo
  bin/
    cloude-test          # Test runner entry-point (no PHPUnit dependency)
  composer.json          # Package manifest (name: cloude/framework)
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
| `Cloude\DateTime` | Tiny immutable date helper extending `\DateTimeImmutable`. Static constructors (`now`/`today`/`parse`/`fromTimestamp`); format shortcuts (`toDateString`/`toTimeString`/`toDateTimeString`/`toIsoString`); arithmetic (`addDays`/`addHours`/`addMinutes`/…); boundaries (`startOfDay`/`endOfMonth`/…); comparisons (`isPast`/`isToday`/`isSameDay`/…); signed `diffIn{Days,Hours,Minutes,Seconds}` and English `diffForHumans()`. Carbon-style `setTestNow()` / `clearTestNow()` for test isolation. Used automatically by the `datetime` cast |
| `Cloude\EventLog` | Fire-and-forget POST to a webhook for usage analytics |
| `Cloude\Format` | Yaml / json / xml / markdown encode-decode dispatcher (string ↔ array) |
| `Cloude\Input` | Wrapper over `$_GET`, `$_POST`, `$_SERVER`, raw body and JSON |
| `Cloude\JsonFile` | Per-request cached, atomic-write helper for JSON files |
| `Cloude\JsonSchema` | In-house JSON Schema subset validator (no external deps) |
| `Cloude\Logger` | File-backed logger with daily rotation and `debug/info/warn/error` |
| `Cloude\TaskRunner` | CLI task runner. `prefix:method` dispatch over registered callables or static class methods, with auto `list` / `help` |
| `Cloude\Router` | Router with `/{param}`, `/{param?}`, `/{param:regex}` patterns, nested route groups, and `get/post/put/patch/delete/any` helpers |
| `Cloude\Session` | Static façade over `$_SESSION` with hardened cookie defaults (`httponly`, `samesite=Lax`, `secure` on HTTPS). Typed `get/set/has/forget/all`, flash messages (`flash`/`pullFlash`/`reflash`), CSRF helpers (`csrfToken`/`checkCsrf`), `regenerate()` for login flows |
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
| `Cloude\Model\Model` | Abstract Active Record. Subclass with `protected static string $table` + `$connection` (+ optional `$types`). CRUD via `find` / `findBy` / `create` / `save` / `delete`. Static helpers: `table()`, `field('col')`, `as('alias')`, `ref()`, `query()` |
| `Cloude\Model\Cast` | Opt-in attribute coercion driven by the `$types` map: `int`, `float`, `string`, `bool`, `decimal[:N]`, `json`/`array`, `datetime[:FMT]`, `date[:FMT]`, `enum:FQCN`. Null passes through |
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
| `Cloude\Storage\Transaction` | `begin` / `commit` / `rollback` / `inTransaction` / `depth` + closure-based `run(fn, $connection)`. Real nested transactions via SAVEPOINTs. Wraps PDO errors as `StorageException` |
| `Cloude\Storage\Schema` | DDL emitter — produces `CREATE TABLE` / `DROP TABLE` SQL from structured arrays. Indexes (`UNIQUE`/`INDEX`), foreign keys (`ON DELETE`/`ON UPDATE`), `DEFAULT NULL`, composite primary keys, MySQL + Postgres dialects. **Not a migration framework** — feed the SQL to your existing tooling |

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

### `Cloude\Testing\…` — built-in test framework (no PHPUnit dependency)

| Class | Responsibility |
|---|---|
| `Cloude\Testing\TestCase` | Test base class. Lifecycle (`setUp`/`tearDown`), PHPUnit-compatible assertion surface (`assertSame`/`assertTrue`/`assertInstanceOf`/…), exception expectations, Cloude-specific helpers (`useArrayModel`, `useSqliteModel`, `useMockModel`, `captureHttp`, `freezeTime`, …) |
| `Cloude\Testing\Assert` | Static assertion library used by `TestCase`. Each method increments an internal counter shown in the runner's summary |
| `Cloude\Testing\Runner` | Discovery + execution + reporting. `bin/cloude-test` calls `Runner::main($argv)`. Supports `--filter=PATTERN` and one or more path arguments |
| `Cloude\Testing\DataProvider` | `#[DataProvider('cases')]` attribute — name a static method returning an iterable of arg arrays; one test invocation per row |
| `Cloude\Testing\AssertionFailedException` | Thrown by `Assert` methods on failure. The runner catches it to flag the test as failed (vs. errored) |
| `Cloude\Testing\MockStorage` | Recording wrapper around `ArrayStorage` for behaviour assertions (`$store->received('update', times: 1)`) |
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

#### Short names inside views

`include`-based templates run in their own scope, so `View::e()` etc.
need the framework namespace. Two equally supported shapes — pick the
one that fits the project:

**Option A — standard `use` statement** (per-view, explicit, IDE-friendly):

```php
<?php // views/layout.html.php ?>
<?php use Cloude\{View, Input, Str}; ?>
<!doctype html>
<title><?= View::e($title) ?></title>
<form><input value="<?= View::e(Input::get('q', '')) ?>"></form>
```

**Option B — declarative aliases in `app/config/app.php`** (one
declaration, every view inherits):

```php
// app/config/app.php
return [
    'aliases' => ['View', 'Input', 'Str', 'DateTime'],
    // …other config
];
```

```php
<?php // views/layout.html.php — no `use` needed ?>
<!doctype html>
<title><?= View::e($title) ?></title>
<form><input value="<?= View::e(Input::get('q', '')) ?>"></form>
<small><?= DateTime::parse($post->created_at)->diffForHumans() ?></small>
```

`Bootstrap::run()` reads the `aliases` list and calls
`class_alias('Cloude\<short>', '<short>')` for each entry. The
framework **skips silently** when the short name is already taken
(your own classes, PHP built-ins, prior aliases) so user code is
never stomped. Default config registers no aliases — the feature is
strictly opt-in.

Bundled examples (`examples/basic/`, `examples/contacts/`,
`examples/library/`) use Option A so they're explicit about every
import. Real apps with many views often prefer Option B to drop the
`use` boilerplate.

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

### `Cloude\Model\Model` — schema declaration

The model subclass IS the schema definition. Four declarative static
properties describe the table's shape; everything else (cast logic,
mass-assignment guard, DDL emission, query builder) reads from them.

| Property       | Purpose                                                                                                              |
|----------------|----------------------------------------------------------------------------------------------------------------------|
| `$properties`  | **Fields the model accepts.** Mass-assignment whitelist. Empty = no whitelist (any key accepted).                    |
| `$types`       | **PHP-side types.** Auto-coerced on read AND write via `Cast::read` / `Cast::write`. **NULL always passes through**. |
| `$indexes`     | **Indexes** (`unique` / `index`). Emitted as standalone `CREATE [UNIQUE] INDEX` statements via `indexesSql()`.       |
| `$foreignKeys` | **Foreign keys** with optional `ON DELETE` / `ON UPDATE`. Emitted as `ALTER TABLE ... ADD CONSTRAINT` via `foreignKeysSql()`. |

```php
class User extends \Cloude\Model\Model
{
    protected static string $table      = 'users';
    protected static string $primaryKey = 'id';

    protected static array $properties = ['id', 'email', 'name', 'role_id', 'active', 'created_at', 'tags', 'status'];

    protected static array $types = [
        'id'         => 'int',
        'active'     => 'bool',
        'tags'       => 'json',
        'created_at' => 'datetime',
        'status'     => 'enum:' . Status::class,
    ];

    protected static array $indexes = [
        ['type' => 'unique', 'columns' => ['email']],
        ['type' => 'index',  'columns' => ['role_id']],
    ];

    protected static array $foreignKeys = [
        ['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'],
         'on_delete' => 'set null', 'on_update' => 'cascade'],
    ];
}
```

**`$indexes` and `$foreignKeys` are metadata-only.** The framework
**emits** the SQL on demand via `indexesSql()` / `foreignKeysSql()`
and stops there. No cascade-from-PHP, no automatic eager loading,
no FK-aware `delete()`, no migration runner, no `up()` / `down()`.
The model declares the contract; the database enforces it (after
you apply the emitted SQL):

```php
foreach (User::indexesSql()     as $sql) $pdo->exec($sql);
foreach (User::foreignKeysSql() as $sql) $pdo->exec($sql);
```

Column SQL types (`VARCHAR(255)`, `BIGINT UNSIGNED`, …) deliberately
live in your migrations, not in `$types`. `$types` is PHP-side
coercion; `$indexes` / `$foreignKeys` are constraint metadata. The
framework doesn't try to be a migration system — only to surface
what the application already knows about its own data.

### `Cloude\Model\Model` — `$types` (attribute coercion details)

Declare a `$types` map on the subclass and the framework normalises
values when reading (storage → PHP) and writing (PHP → storage). Null
always passes through — nullable columns stay nullable.

```php
use Cloude\Model\Model;

enum Status: string { case Active = 'active'; case Banned = 'banned'; }

class Product extends Model
{
    protected static string $table = 'products';
    protected static array  $types = [
        'id'          => 'int',
        'price'       => 'decimal:2',
        'in_stock'    => 'bool',
        'tags'        => 'json',
        'created_at'  => 'datetime',
        'status'      => 'enum:' . Status::class,
    ];
}

$p = Product::find(1);
$p->price;          // string "12.50"   (preserves precision; use bcmath for arithmetic)
$p->in_stock;       // bool
$p->tags;           // array
$p->created_at;     // \DateTimeImmutable
$p->status;         // Status::Active
```

| Type                       | Read (storage → PHP)                                | Write (PHP → storage)                  |
|----------------------------|-----------------------------------------------------|----------------------------------------|
| `int` / `integer`          | `(int)`                                             | `(int)`                                |
| `float` / `double` / `real`| `(float)`                                           | `(float)`                              |
| `string`                   | `(string)`                                          | `(string)`                             |
| `bool` / `boolean`         | `filter_var(BOOLEAN)` (`1`/`true`/`yes`/`on` → true)| `1` or `0` (DB-friendly int)           |
| `decimal[:N]`              | `number_format(v, N, '.', '')` — N defaults to 2    | same                                   |
| `json` / `array`           | `json_decode(true)`                                 | `json_encode`                          |
| `datetime[:FMT]`           | `new \DateTimeImmutable($v)`                        | `$v->format($FMT ?? 'Y-m-d H:i:s')`    |
| `date[:FMT]`               | same                                                | `$v->format($FMT ?? 'Y-m-d')`          |
| `enum:FQCN`                | `$FQCN::from($v)` (BackedEnum)                      | `$v->value`                            |

Apply points: `Model::hydrate()` (called by `find` / `findBy` / `all`),
`Model::refresh()`, and `Model::save()` for writes. `toArray()`
returns the raw PHP-typed attributes; pass `serialize: true` to get the
write-cast scalars (handy for `Response::json($u->toArray(true))`).

Leave `$types` empty (the default) to opt out — the model behaves
exactly as before. Unknown cast types throw `\InvalidArgumentException`
so typos surface fast.

### `Cloude\Model\Model` — static table / field / alias helpers

Every subclass exposes four static helpers built on top of `$table`:

| Call                  | Returns                          | Used for                                            |
|-----------------------|----------------------------------|-----------------------------------------------------|
| `User::table()`       | `'users'`                        | Raw SQL, FROM clauses                               |
| `User::field('email')`| `'users.email'`                  | Qualified column references in `select` / `where` / `join` |
| `User::ref()`         | `TableRef('users', null)`        | Pass as `from()` / `join()` target without aliasing |
| `User::as('u')`       | `TableRef('users', 'u')`         | Aliased FROM / JOIN — call `$u->field('email')` for `'u.email'` |
| `User::alias('name', 'user_name')` | `['users.name', 'user_name']` | Tuple `[col, alias]` for `Query::select()`. Same shape via `TableRef::alias()` when you want the table's alias instead of its name |

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
them automatically via `Identifier::qualify()`. Same for tables passed
as strings to `from()` / `join()` (`'users AS u'`).

**Aliasing a column.** Two shapes; the tuple is preferred:

```php
$q->select('id', ['name', 'type_name']);          // recommended — typed tuple
$q->select('id', 'name AS type_name');            // also accepted (legacy / hand-written)

// Same with helpers that emit the tuple shape:
$q->select('id', User::alias('name', 'type_name'));
//   ['users.name', 'type_name']  → `users`.`name` AS `type_name`

$u = User::as('u');
$q->from($u)->select($u->alias('name', 'who'));
//   ['u.name', 'who']            → `u`.`name` AS `who`
```

The tuple form (`[column, alias]`) sidesteps any whitespace / keyword
parsing and composes naturally with `Model::alias()` and
`TableRef::alias()`. Use it for any aliased column in new code.

For typed aliased joins, pair `Model::as()` with `from()`:

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

### `Cloude\Storage\Transaction` — begin / commit / rollback

Thin layer over `Connection::pdo($name)` with two real benefits over
`$pdo->beginTransaction()` directly:

1. **Real nested transactions** via SAVEPOINTs (most PDO drivers reject
   a second native `BEGIN`).
2. **Closure form** that auto-commits on return and auto-rolls-back on
   any `\Throwable`.

Failures surface as `Cloude\Storage\StorageException` (or one of its
specialised subclasses), not raw `\PDOException` — same wrapping the
Query builder already does.

#### Closure form (recommended)

```php
use Cloude\Storage\Transaction;

$orderId = Transaction::run(function () use ($payload) {
    $order = Order::create($payload);
    Inventory::reserve($order->id, $payload['items']);
    return $order->id;
});
// Throws → ROLLBACK (and rethrows). Returns → COMMIT.
```

#### Manual form

```php
Transaction::begin();
try {
    Order::create([...]);
    AuditLog::create([...]);
    Transaction::commit();
} catch (\Throwable $e) {
    Transaction::rollback();
    throw $e;
}
```

#### Inspection

```php
Transaction::inTransaction();    // bool — any depth > 0
Transaction::depth();            // 0 outside, 1 = BEGIN, 2+ = SAVEPOINT levels
```

#### Nested calls

A second `Transaction::begin()` (or a nested `Transaction::run(...)`)
issues `SAVEPOINT cloude_sp_1`. Inner `commit` releases the savepoint,
inner `rollback` rolls back to it without dropping outer work:

```php
Transaction::run(function () {
    Order::create(...);                        // outer work

    try {
        Transaction::run(fn () => Risky::do());  // SAVEPOINT
    } catch (\Throwable) {
        // inner rollback already happened; outer keeps going
    }

    AuditLog::create(...);                     // also kept
});
```

#### Non-default connection

Every method takes an optional `$connection` arg (defaults to
`'default'`):

```php
Transaction::run($fn, 'analytics');
Transaction::begin('replica_writes');
```

Each connection has its own depth counter; transactions on different
connections don't interfere.

#### Out of scope

- Distributed / two-phase commit
- Automatic retry on serialization-failure / deadlock (caller wraps
  with their own retry loop)
- Saga / outbox pattern coordination

### `Cloude\Storage\Schema` — DDL emitter

Tiny declarative `CREATE TABLE` builder. **Not a migration framework**:
no versioning, no up/down, no diffing. Produces SQL strings that you
feed to `phinx` / `doctrine/migrations` / `pdo->exec()` / a one-off
install script — whatever fits the project. Targets MySQL (default)
and Postgres (`dialect: 'pgsql'`).

```php
use Cloude\Storage\Schema;

$sql = Schema::createTableSql('users', [
    'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false, 'auto_increment' => true, 'primary' => true],
    'email'      => ['type' => 'VARCHAR(255)', 'null' => false],
    'role_id'    => ['type' => 'BIGINT',       'unsigned' => true, 'null' => true,  'default' => null],
    'created_at' => ['type' => 'DATETIME',     'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
], indexes: [
    ['type' => 'unique', 'columns' => ['email']],
    ['type' => 'index',  'columns' => ['role_id']],
], foreignKeys: [
    [
        'columns'    => ['role_id'],
        'references' => 'roles',
        'on'         => ['id'],
        'on_delete'  => 'set null',
        'on_update'  => 'cascade',
    ],
]);
// → CREATE TABLE `users` (
//     `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
//     `email` VARCHAR(255) NOT NULL,
//     `role_id` BIGINT UNSIGNED NULL DEFAULT NULL,
//     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
//     UNIQUE KEY `uq_users_email` (`email`),
//     KEY `idx_users_role_id` (`role_id`),
//     CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`)
//       REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
//   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**Standalone index / FK emitters** — when the table already exists and
you only want to attach indexes or foreign keys after the fact:

```php
echo Schema::indexSql('users', ['type' => 'unique', 'columns' => ['email']]);
// → CREATE UNIQUE INDEX `uq_users_email` ON `users` (`email`)

echo Schema::foreignKeySql('orders', [
    'columns'    => ['user_id'],
    'references' => 'users',
    'on'         => ['id'],
    'on_delete'  => 'set null',
]);
// → ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_user_id`
//   FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
```

**On the Model side** — subclasses can declare `$indexes` and
`$foreignKeys` and get the same SQL through two convenience methods.
Columns themselves stay in your migrations (the Model doesn't carry
`$columns`); these properties describe the constraint surface for
documentation + on-demand emission:

```php
class User extends Model {
    protected static string $table = 'users';

    protected static array $indexes = [
        ['type' => 'unique', 'columns' => ['email']],
        ['type' => 'index',  'columns' => ['role_id']],
    ];

    protected static array $foreignKeys = [
        ['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'],
         'on_delete' => 'set null', 'on_update' => 'cascade'],
    ];
}

foreach (User::indexesSql() as $sql) { $pdo->exec($sql); }
foreach (User::foreignKeysSql() as $sql) { $pdo->exec($sql); }
```

`User::indexesSql()` / `foreignKeysSql()` return `list<string>` —
empty when nothing's declared.

**Column descriptor** keys: `type` (required), `null` (default `true`),
`default` (omitted unless set; `null` emits `DEFAULT NULL`, SQL
keywords like `CURRENT_TIMESTAMP` pass through, anything else gets
quoted with `'…'`), `auto_increment` (MySQL), `unsigned` (MySQL),
`primary` (composite PKs get a table-level `PRIMARY KEY (col1, col2)`
declaration automatically), `comment`.

**Foreign-key referential actions**: `cascade`, `set null`, `restrict`,
`no action`, `set default` (case-insensitive). Anything else throws.

**Always emitted.** `ON DELETE` and `ON UPDATE` are written into every
emitted FK statement, even when the declaration omits one or both
keys. The default is `NO ACTION` (the SQL standard). This keeps the
generated SQL explicit — the database (and any reader of the ALTER
TABLE) sees the exact referential semantics, with no fallback to
driver / engine implicit defaults.

```php
Schema::foreignKeySql('orders', [
    'columns'    => ['user_id'],
    'references' => 'users',
    'on'         => ['id'],
    // on_delete / on_update omitted
]);
// → ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_user_id`
//   FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
//   ON DELETE NO ACTION ON UPDATE NO ACTION
```

**SQLite caveat:** the generated SQL uses MySQL-style `UNIQUE KEY <name> (cols)`
inside the CREATE which SQLite doesn't parse. Either skip the indexes
on SQLite or write them as `UNIQUE (cols)` (no name) by hand. Schema's
primary target is MySQL/Postgres.

### `Cloude\Session` — session façade

```php
use Cloude\Session;

// Typed access — the first call auto-starts the session with hardened defaults.
Session::set('user_id', 42);
$id = Session::get('user_id', $default = null);
Session::has('user_id');
Session::forget('user_id');
$snapshot = Session::all();        // excludes internal flash/CSRF buckets

// Flash — value survives exactly one redirect
Session::flash('success', 'Saved.');
$msg = Session::pullFlash('success');   // null on the originating request,
                                        // 'Saved.' on the next one (then gone)
Session::reflash('success');            // keep it for one more cycle

// CSRF
$token = Session::csrfToken();          // minted on first call, stable per session
if (!Session::checkCsrf((string) Input::post('_csrf'))) {
    throw new \Cloude\Http\HttpException(419, 'CSRF token mismatch');
}

// On login (prevents fixation)
Session::regenerate();

// On logout
Session::destroy();
```

**Lazy auto-start.** The first `Session::*` call starts the session
transparently with the hardened defaults below. Routes that **never**
touch session state never start one — no cookie is issued, no header
is set, no save-handler runs. Pure-JSON / MCP endpoints can ignore
the class entirely without any opt-out flag.

**Hardened defaults applied on auto-start**: `httponly = true`,
`samesite = 'Lax'`, `secure = true` when HTTPS is on. Override by
calling `start()` explicitly BEFORE the first `set` / `get` — it's
idempotent so the first call wins:

```php
Session::start([], cookieParams: [
    'samesite' => 'Strict',
    'domain'   => '.example.com',
]);
// any subsequent Session::* calls in this request reuse the
// configured params; auto-start no longer fires.
```

**Out of scope** (deliberately): user / role / permission models,
"remember me" cookies, login throttling, OAuth / OIDC clients,
session-backed queue. Build those on top using `Cloude\Model` for
storage and `Session` for the cookie layer.

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
    'timezone' => Cloude\Config::env('TZ', 'UTC'),   // Bootstrap::run() applies this
    'paths' => [
        'data'  => BASEPATH . '/data',
        'views' => APPPATH . '/views',
    ],
];
```

The framework ships `config/app.php` with `'timezone' => 'UTC'` as a
baseline. Apps that don't override the key inherit `UTC`; otherwise
the app's value wins (deep-merge).

`baseUrl()` resolves in this order: `BASE_URL` global constant (if
already defined), `app.base_url` config, `BASE_URL` env var,
auto-detected scheme + Host (validated against the optional allowlist;
non-allowed hosts collapse to `localhost` to prevent header injection).
The result is memoized for the request — `Config::reset()` clears it.

### `Cloude\DateTime`

Tiny immutable date helper extending `\DateTimeImmutable`. Drop-in for
any `\DateTimeInterface` consumer; adds the shortcuts you'd otherwise
copy-paste between projects. The `datetime` cast in `Model::$types`
hydrates into this class automatically, so the helpers work on attribute
values without any extra wiring.

```php
use Cloude\DateTime;

// Static constructors
DateTime::now();                        // current moment
DateTime::today();                      // today at 00:00:00
DateTime::parse('2026-05-18 14:30');    // throws \InvalidArgumentException on bad input
DateTime::fromTimestamp(1716000000);

// Format shortcuts
$d->toDateString();        // 'Y-m-d'         → '2026-05-18'
$d->toTimeString();        // 'H:i:s'         → '14:30:00'
$d->toDateTimeString();    // 'Y-m-d H:i:s'   → '2026-05-18 14:30:00'
$d->toIsoString();         // 'c' / RFC 3339  → '2026-05-18T14:30:00+02:00'
(string) $d;               // 'Y-m-d H:i:s' — MySQL-shaped, drops the offset
                           //   Use toIsoString() when you need timezone in the output.
                           //   Interpolation works: "saved at $d" → "saved at 2026-05-18 …"

// Arithmetic (immutable — always returns a new instance)
$d->addDays(7)->subHours(2)->addMinutes(30);
$d->addWeeks(1); $d->addMonths(1); $d->addYears(1);
$d->subSeconds(10); $d->subDays(3); // ... etc

// Boundaries
$d->startOfDay();          // 00:00:00
$d->endOfDay();            // 23:59:59
$d->startOfMonth();        // 1st of the month at 00:00:00
$d->endOfMonth();          // last of the month at 23:59:59

// Comparisons
$d->isPast();     $d->isFuture();
$d->isToday();    $d->isYesterday();  $d->isTomorrow();
$d->isBefore($other);  $d->isAfter($other);  $d->isSameDay($other);

// Signed diffs — positive when $other is later than $this
$d->diffInSeconds($other);
$d->diffInMinutes($other);
$d->diffInHours($other);
$d->diffInDays($other);

// English "5 minutes ago" / "in 3 days"
$d->diffForHumans();           // vs now()
$d->diffForHumans($reference); // vs explicit reference (testable)
```

**Not in scope** by design: localised relative strings (use
`IntlDateFormatter::formatRelative` for multi-language), business-day
arithmetic, calendar systems. Standard PHP `format()` / `setTimezone()`
/ `setTime()` / `modify()` etc. are inherited untouched.

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
Cache::ok();                          // 1d at the CDN, browser revalidates every request
Cache::ok(3600);                      // 1h at the CDN, browser revalidates every request
Cache::ok(86400, 300);                // 1d at the CDN, 5min in the browser
Cache::notFound();                    // short CDN TTL on 404
Cache::unavailable();                 // no-store + Retry-After on 5xx

if (Cache::conditionalGet(filemtime($path))) {
    return; // 304 sent
}
```

`ok($stimeout, $timeout)` splits the TTL between the **shared cache**
(`s-maxage` — CDN / reverse proxy) and the **browser** (`max-age`).
Default `$timeout = 0` lets the browser hit the CDN on every request
and get an instant 304 via `conditionalGet()` when nothing's changed.

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
composer test        # cloude-test (Cloude\Testing\Runner)
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

### `Cloude\Domain\…` — DDD helpers (optional)

A handful of thin base classes for projects organised in the DDD style.
Everything in this namespace is **opt-in** — the framework still works
fine for Transaction Script and MVC apps that ignore it entirely. See
[`PATTERNS.md`](PATTERNS.md) for when each pattern fits.

| Class                              | Responsibility                                                                                |
|------------------------------------|-----------------------------------------------------------------------------------------------|
| `Cloude\Domain\ValueObject`        | Abstract base for value objects. Structural `equals()` + `\Stringable` contract               |
| `Cloude\Domain\DomainException`    | Marker class for invariant violations (extends `\DomainException`)                            |
| `Cloude\Domain\DomainEvent`        | Marker interface — single method `occurredOn(): \DateTimeImmutable`                           |
| `Cloude\Domain\AggregateRoot`      | Abstract base that owns a per-instance event queue: `recordEvent()` + `pullDomainEvents()`    |

```php
use Cloude\Domain\{ValueObject, AggregateRoot, DomainEvent, DomainException};

final class Money extends ValueObject
{
    public function __construct(
        public readonly int $amount,        // cents
        public readonly string $currency,
    ) {
        if ($amount < 0) {
            throw new DomainException('Money cannot be negative');
        }
    }
    public function __toString(): string {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }
}

final class BookBorrowed implements DomainEvent
{
    public function __construct(
        public readonly string $isbn,
        public readonly string $memberId,
        public readonly \DateTimeImmutable $when,
    ) {}
    public function occurredOn(): \DateTimeImmutable { return $this->when; }
}

final class Book extends AggregateRoot
{
    public function __construct(
        public readonly string $isbn,
        public readonly string $title,
        private int $copiesAvailable,
    ) {}

    public function borrow(string $memberId): void {
        if ($this->copiesAvailable === 0) {
            throw new DomainException("No copies of '{$this->title}' available");
        }
        $this->copiesAvailable--;
        $this->recordEvent(new BookBorrowed($this->isbn, $memberId, new \DateTimeImmutable()));
    }
}

// Application layer:
$book->borrow($memberId);
$repo->save($book);
foreach ($book->pullDomainEvents() as $event) {
    $eventLog->record($event);
}
```

**Deliberately not shipped:** event bus / dispatcher (return the events
to the application, dispatch how you want), repository base class
(write a domain-specific interface + a Cloude-backed adapter, see
[`examples/library/`](examples/library/)), specification pattern.

### `Cloude\Testing` — standalone test framework

Cloude ships its own test runner — no PHPUnit dependency. The CLI
entry point lives at `bin/cloude-test` (or run it via `composer test`).

```bash
vendor/bin/cloude-test                       # run tests/ (default)
vendor/bin/cloude-test tests/Storage         # scope to a directory
vendor/bin/cloude-test --filter=Cast         # regex match on ClassName::method
vendor/bin/cloude-test --help                # usage summary
```

The runner discovers `*Test.php` files recursively, instantiates every
class extending `Cloude\Testing\TestCase`, and runs each public
method whose name starts with `test`. Dots / `F` / `E` printed
PHPUnit-style; final summary lists failures and errors with stack
traces. Exit code 0 on green, 1 on anything red.

**Why ship a custom runner?** The framework's philosophy is small,
hand-rolled, no dependencies the user didn't ask for. Dropping PHPUnit
(15+ MB in `vendor/`) keeps `composer install` lean for downstream
consumers; the runner itself is < 500 LOC across `Runner.php`,
`TestCase.php`, `Assert.php` and `bin/cloude-test`. PHPUnit's API
shape (method names, attributes, lifecycle) is mirrored so the muscle
memory transfers and existing tests migrate with two `use`
substitutions.

#### Writing a test

```php
use Cloude\Testing\DataProvider;
use Cloude\Testing\TestCase;

final class StrTest extends TestCase
{
    protected function setUp(): void { /* per-test fixture */ }
    protected function tearDown(): void { /* per-test cleanup */ }

    public function testSlugAscii(): void
    {
        self::assertSame('hello-world', \Cloude\Str::slug('Hello World'));
    }

    public function testInvalidInputThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        \Cloude\Str::slug('');
    }

    #[DataProvider('truthyCases')]
    public function testBool(string $input, bool $expected): void
    {
        self::assertSame($expected, \Cloude\Config::boolEnv($input, false));
    }

    public static function truthyCases(): array
    {
        return [
            'yes'   => ['yes',   true],
            'no'    => ['no',    false],
            'empty' => ['',      false],
        ];
    }
}
```

#### Assertions

PHPUnit-compatible names (`assertSame`, `assertTrue`, etc.) are
available both as `$this->...` and `self::...` (route to the same
implementation). The full list:

| Equality        | `assertSame`, `assertNotSame`, `assertEquals`, `assertEqualsWithDelta` |
| Booleans / null | `assertTrue`, `assertFalse`, `assertNull`, `assertNotNull` |
| Containers      | `assertCount`, `assertEmpty`, `assertNotEmpty`, `assertContains`, `assertArrayHasKey`, `assertArrayNotHasKey` |
| Type            | `assertInstanceOf`, `assertNotInstanceOf`, `assertIsString` |
| Strings         | `assertStringContainsString`, `assertStringNotContainsString`, `assertStringStartsWith`, `assertStringEndsWith`, `assertMatchesRegularExpression`, `assertJson` |
| Comparison      | `assertGreaterThan`, `assertLessThan`, `assertLessThanOrEqual` |
| Filesystem      | `assertFileExists`, `assertDirectoryExists` |
| Escape hatch    | `fail($message)` |

#### Cloude-specific helpers

| Helper                                     | Purpose                                                                                  |
|--------------------------------------------|------------------------------------------------------------------------------------------|
| `useArrayModel(class, rows = [])`          | Configure a `Model` subclass with `ArrayStorage` + seed rows for the duration of the test |
| `useSqliteModel(class, createSql)`         | Same, but with an in-memory SQLite + `PdoStorage`. Returns the PDO for extra setup        |
| `useMockModel(class, rows = [])`           | Like `useArrayModel()` but the storage records every call. Returns a `MockStorage` for assertions |
| `captureHttp($handler)`                    | Run a route handler; return `['status' => …, 'body' => …]`                               |
| `assertJsonResponse($expected, $handler)`  | Capture + decode + structural compare. Optional `status:` named arg                       |
| `assertHttpException($status, $handler)`   | Catch a `Cloude\Http\HttpException`; check status; return the exception for chaining     |
| `freezeTime($when)` / `unfreezeTime()`     | Pin `DateTime::now()` to a fixed instant for deterministic time-aware tests              |
| `assertModelReceived($store, $method, times: ?int)` | Assert that a `MockStorage` got `$method` (optionally exactly `$times` times)        |
| `assertModelDidNotReceive($store, $method)` | Inverse — assert `$method` was never called                                              |
| `assertModelHas($model, $attributes)`      | Assert each attribute key in `$attributes` matches on the model                          |

**Picking a model helper:**

| Need                                                          | Helper            |
|---------------------------------------------------------------|-------------------|
| State-based test ("after doing X, the row has these fields")  | `useArrayModel`   |
| Behaviour test ("X causes a delete on PK 42")                 | `useMockModel`    |
| Tests that go through `Model::query()` (joins, raw SQL builder) | `useSqliteModel`  |

`useMockModel` is the right choice when you want to assert _how_ the
code used the storage. Don't use it for code that calls
`Model::query()` — faking the SQL builder leads to tests that pass
even when the underlying SQL is wrong. SQLite in-memory (the
`useSqliteModel` path) is fast enough that real SQL is the better
default whenever the query shape matters.

State that bleeds across tests is automatically cleared in
`setUp()` / `tearDown()`:

- `Cloude\Config::reset()` — forgets all loaded config files
- `Cloude\DateTime::clearTestNow()` — releases any frozen `now()`

```php
use Cloude\Testing\TestCase;
use Cloude\Http\NotFoundException;

final class BorrowingTest extends TestCase
{
    public function test_returns_404_when_book_missing(): void
    {
        $this->useArrayModel(Book::class, []);
        $this->assertHttpException(404, function (): void {
            Book::find('does-not-exist') ?? throw new NotFoundException('book');
        });
    }

    public function test_borrow_records_event_at_frozen_time(): void
    {
        $when = $this->freezeTime('2026-05-18 12:00:00');
        $this->useArrayModel(Book::class, [['isbn' => '978', 'copies' => 1]]);

        $book = Book::find('978');
        $book->borrow('member-42');

        $events = $book->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertSame($when->getTimestamp(), $events[0]->occurredOn()->getTimestamp());
    }

    public function test_controller_deletes_user_on_ban(): void
    {
        $store = $this->useMockModel(User::class, [
            ['id' => 1, 'email' => 'ada@x', 'active' => 1],
        ]);

        (new BanUserController())->ban(1);

        $this->assertModelReceived($store, 'update', times: 1);
        $this->assertModelDidNotReceive($store, 'delete');
        self::assertSame([1, ['email' => 'ada@x', 'active' => 0]], $store->lastCall('update'));
    }
}
```

## Philosophy

- **No magic**: the code you read is the code that runs. No generators, annotations or proxies.
- **Small classes**: each class fits in a file you can read in one sitting — and so can [Claude Code](https://claude.com/claude-code).
- **No required dependencies**: the core pulls nothing in. `ext-intl` is recommended for slug transliteration but not required.
- **No global state**: no container, no singletons. Static classes are just namespaces for functions.
- **AI-readable by design**: explicit APIs, no DSL, no inheritance webs. The framework is small on purpose so an agent can edit any piece without missing context.

## License

MIT - see [LICENSE](LICENSE).
