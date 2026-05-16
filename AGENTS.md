# AGENTS.md — guide for AI coding agents

> Reference for AI agents (Claude Code, Cursor, Codex, …) writing code that
> **consumes** `cloude/framework`. If you're modifying the framework itself,
> follow the inline class docblocks and `composer cs-check` instead.

This file is intentionally short. The framework's surface area is small enough
that you should be able to load it once into context and keep going.

For **architecture pattern guidance** (Transaction Script vs. MVC +
Repository vs. DDD layered — when to pick each, smells that mean it's
time to graduate, anti-patterns to avoid), see
[`PATTERNS.md`](PATTERNS.md). It maps "what your app looks like" to
"which example to copy".

## Mental model

- **One class per file. No magic. No DSL. No container.** What you read is
  what runs. Build a `Cloude\Router`, register routes, dispatch.
- **Stateless static utilities** for everything except where instance state
  is fundamental (`Logger`, `TaskRunner`, `Mcp\Server`, `AssetUrl` after
  `configure()`, `Markdown::useParser()`).
- **Files are the default storage model**: JSON and Markdown on disk,
  accessed via `Cloude\JsonFile`, `Cloude\Markdown\File` and the
  `Cloude\Data\*Repository` base classes.
- **Relational data is opt-in** via `Cloude\Model` — a thin Active Record
  over a `Storage` interface. Adapters: `PdoStorage` (MySQL / Postgres /
  SQLite), `JsonStorage` (one file per row), `ArrayStorage` (in-memory).
- **PSR-4 only**, namespace `Cloude\`. Consumer projects typically use
  namespace `App\` mapped to `app/classes/`.

## Bootstrapping a project

The canonical `www/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config.php';   // defines BASE_URL, DEBUG

if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;   // PHP dev-server static-file passthrough
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: __DIR__ . '/../app/views',
);

$router = new \Cloude\Router(BASE_URL);
// ...register routes...
$router->dispatch();
```

`Bootstrap::run()` wires `ob_start`, `Http\ErrorHandler::register` and
`View::setBasePath` in one call. Don't roll those by hand.

`app/config.php` should use `Cloude\Config`:

```php
\Cloude\Config::defineBaseUrl(['example.com', 'localhost']);
\Cloude\Config::defineDebug();
if (!defined('DATA_DIR')) define('DATA_DIR', dirname(__DIR__) . '/data');
```

## Decision matrix — when to use what

| You want to… | Use | Notes |
|---|---|---|
| Send a JSON response | `Http\Response::json($data, $status, $pretty)` | Don't `header()` + `echo json_encode()` by hand |
| 404 / redirect / 204 | `Response::notFound`, `redirect`, `noContent` | |
| Cache a 200 at the CDN | `Http\Cache::ok($seconds)` | Sets both `Cache-Control` and `CDN-Cache-Control` |
| Conditional GET (304) | `Cache::conditionalGet(filemtime($path))` | Returns true when client is fresh |
| Read JSON file (cached) | `JsonFile::read($path)` / `readOr($path, $default)` | Per-request cache; `null` on missing/invalid |
| Write JSON atomically | `JsonFile::write($path, $data, $pretty)` | Temp + rename |
| Encode/decode by type | `Format::json($input)`, `Format::yaml`, `Format::xml`, `Format::markdown` | Dispatches by `string` ↔ `array` |
| Validate against JSON Schema | `JsonSchema::validate($data, $schema)` | Returns errors list, empty = valid |
| Slug / transliterate | `Str::slug`, `Str::ascii` | Needs `ext-intl` for non-Latin |
| Random tokens, UUIDs, hashes | `Str::random()`, `Str::uuid()`, `Str::hash()` | |
| Case conversion | `Str::camel/pascal/snake/kebab` | Handles camel-case + non-alnum boundaries |
| Mask for privacy | `Str::mask('+34600123456', '*', 4, -3)` | Negative length keeps a tail visible |
| Truncate by the middle | `Str::truncateMiddle($path, 25)` | Keeps both ends, drops the middle — paths, hashes, breadcrumbs |
| Dot-path access | `Arr::get($a, 'foo.bar.baz', $default)` | Also `set/has/forget/pluck/dot/undot/merge` |
| Pipeline data | `Collection::make($rows)->filter(...)->sortBy(...)->take(...)->pluck(...)->all()` | Implements `ArrayAccess`, `Countable`, iterable |
| Directory of `.json` per entity | extend `Data\JsonRepository` | Override `transform($data, $slug)` |
| Directory of `.md` per entity | extend `Data\MarkdownRepository` | Reads `.md.gz` transparently |
| Markdown → HTML | `Markdown::toHtml($md)` | In-house parser; no Parsedown |
| Markdown frontmatter + body | `Markdown::parse($content)` | Returns `meta`, `html`, `paragraphs`, `description`, `noindex` |
| Serve a `.md` over HTTP | `Markdown\Server::serve($path, $canonical)` | 404 / 304 / canonical / gzip passthrough |
| MCP (Model Context Protocol) server | `new Mcp\Server(...)`, `tool()`, `resourceProvider()`, `resourceReader()` | HTTP / JSON-RPC 2.0; auto-validates `inputSchema` |
| CLI script | `Cli::parseArgs($argv)` + `flag/option/positional` + `info/warn/error/success/abort` | TTY-gated colors |
| Group CLI scripts | `TaskRunner::register / registerClass` | One entry-point script with `prefix:method` dispatch |
| File log with daily rotation | `new Logger($path, minLevel: 'info')` | |
| Fire-and-forget webhook | `EventLog::send($payload)` | curl_multi at shutdown |
| Send email (SMTP / sendmail) | `Mailer::forge()->send([...])` | `Cloude\Mail\*`; AUTH LOGIN + STARTTLS for SMTP, no attachments |
| Versioned asset URLs (`/{mtime}/assets/…`) | `Http\AssetUrl::configure(...)` then `AssetUrl::get($rel)` | Apache rewrite required |

## Idioms

- **Validate at the edge.** `Mcp\Server` validates `arguments` against
  `inputSchema` before invoking the handler. For HTTP routes do
  `JsonSchema::validate($input, $schema)` early and `Response::json($errors, 422)`
  on failure.
- **Pluck dot-paths.** `Collection::pluck('meta.title', '_slug')` works
  because `Arr::get` is dot-aware.
- **Repositories** subclass `Data\JsonRepository` /
  `Data\MarkdownRepository`. Override `transform()` to lift frontmatter
  onto the row, attach the slug, normalize types. `all()` returns a
  `Collection`, ready to pipeline.
- **One file per task** when scripting batch jobs: a public-static method
  on a class registered via `TaskRunner::registerClass($prefix, $class)`.
  Method docblock first line becomes the description shown by
  `tasks.php list`.
- **HTML escape** with `View::e($text)`. Don't write a custom `esc()`.
- **Discovery endpoints** (`/.well-known/mcp.json`, `/llms.txt`,
  `/sitemap.xml`) live in regular route handlers — `Mcp\Server::respondManifest`
  for MCP, `Format::xml` + `Response::xml` for sitemaps.

## Anti-patterns

Don't:

- Reach for an HTTP client wrapper. Use `guzzlehttp/guzzle` directly for
  outbound HTTP — Cloude doesn't ship one.
- Try to find a DI container. There isn't one, by design.
- Plug Parsedown via `Markdown::useParser()` "just in case" — only do it
  if you need a Parsedown-specific feature the in-house parser doesn't
  cover (footnotes, reference links, definition lists). GFM tables are
  supported natively since v0.26.
- Build a custom autoloader. Composer PSR-4 is sufficient.
- Use `class_alias()` to bridge legacy global class names to namespaced
  ones — migrate the call sites and add `use App\Foo;` instead.
- Re-implement features the framework already provides (json response
  helpers, atomic JSON write, gzip-transparent markdown read, etc.).

## What the framework deliberately does NOT include

- **No migrations, no relations, no observers** in `Cloude\Model`.
  CRUD by primary key + `findBy` by equality, plus a thin
  `Cloude\Db\Query` builder for the 80% of queries that aren't joins
  (SELECT/INSERT/UPDATE/DELETE + WHERE + ORDER BY + LIMIT/OFFSET). For
  joins, unions, subqueries or aggregations beyond `count()`, drop to
  the underlying PDO connection.
- **No HTTP client.** Use Guzzle directly.
- **No template engine.** Plain PHP via `View::render`.
- **No session / auth helpers.** Use `$_SESSION` and a route-level check
  before `$router->dispatch()`. For MCP, validate keys inside the handler.
- **No SSE or stdio MCP transport.** HTTP only.
- **No JSON Schema features beyond the listed subset** (`type`, `required`,
  `properties`, `additionalProperties`, `enum`, `items`, `min/maxItems`,
  `min/maximum`, `min/maxLength`, `pattern`). No `$ref`, no `oneOf`. Use
  `opis/json-schema` directly if you need them.

## Recipes (read these before writing equivalents)

Located at `vendor/cloude/framework/examples/recipes/`. Each file is
self-contained, runnable, and copy-pasteable.

| File | Pattern |
|---|---|
| `sitemap.php` | XML sitemap (and sitemap-index) with `Format::xml` + `Response::xml` |
| `jsonld.php` | Schema.org JSON-LD (Article / BreadcrumbList / FAQPage) with `Format::json` |
| `mcp.php` | MCP server with two tools and a static resource catalogue |
| `tasks.php` | TaskRunner with one inline task and a task class |
| `data.php` | Custom `JsonRepository` / `MarkdownRepository` subclasses |

## When in doubt

1. **Read the class docblock.** Every class starts with a 5–15 line
   summary of its purpose, edge cases, and idioms.
2. **Check the recipes** for the use case you're building.
3. **`README.md`** has the per-class reference with code examples.
4. If you can't find a class for the task, the framework probably
   doesn't ship one — and that's deliberate. Write the small bit of
   plain PHP and move on.
