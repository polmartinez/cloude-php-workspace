# Idioms, anti-patterns, scope

> Part of the [AGENTS](../../AGENTS.md) reference. The "how Cloude
> code is shaped" page — patterns to reach for, patterns to avoid,
> and what the framework deliberately won't grow.

## Idioms

- **Throw, don't render, when something is missing.** `Response::notFound`
  is for handlers that already know they want a 404 body. For "I asked
  the repo and it returned null", throw `Cloude\Http\NotFoundException`
  — `ErrorHandler` picks the status, the right view (`404.html.php`),
  and the right format (HTML / JSON / CLI text). The HTML view ships
  with the framework; drop a `404.html.php` under your `viewBase` to
  override it. For other statuses use `Cloude\Http\HttpException(int
  $status, string $message)` directly.
- **Validate at the edge.** `Mcp\Server` validates `arguments` against
  `inputSchema` before invoking the handler. For HTTP routes do
  `JsonSchema::validate($input, $schema)` early and
  `Response::json($errors, 422)` on failure.
- **Pluck dot-paths.** `Collection::pluck('meta.title', '_slug')` works
  because `Arr::get` is dot-aware.
- **Repositories** subclass `Data\JsonRepository` /
  `Data\MarkdownRepository`. Override `transform()` to lift frontmatter
  onto the row, attach the slug, normalise types. `all()` returns a
  `Collection`, ready to pipeline.
- **One file per task** when scripting batch jobs: a public-static
  method on a class registered via
  `TaskRunner::registerClass($prefix, $class)`. Method docblock first
  line becomes the description shown by `tasks.php list`.
- **HTML escape** with `View::e($text)`. Don't write a custom `esc()`.
- **Discovery endpoints** (`/.well-known/mcp.json`, `/llms.txt`,
  `/sitemap.xml`) live in regular route handlers —
  `Mcp\Server::respondManifest` for MCP,
  `Format::xml` + `Response::xml` for sitemaps.

## Anti-patterns

Don't:

- Reach for an HTTP client wrapper. Use `guzzlehttp/guzzle` directly
  for outbound HTTP — Cloude doesn't ship one.
- Try to find a DI container. There isn't one, by design.
- Plug Parsedown via `Markdown::useParser()` "just in case" — only do
  it if you need a Parsedown-specific feature the in-house parser
  doesn't cover (footnotes, reference links, definition lists). GFM
  tables are supported natively since v0.26.
- Build a custom autoloader. Composer PSR-4 is sufficient.
- Use `class_alias()` to bridge legacy global class names to namespaced
  ones — migrate the call sites and add `use App\Foo;` instead.
- Re-implement features the framework already provides (json response
  helpers, atomic JSON write, gzip-transparent markdown read, etc.).

## What the framework deliberately does NOT include

- **No migrations, no relations, no observers** in `Cloude\Model`.
  CRUD by primary key + `findBy` by equality, plus a `Cloude\Storage\Query`
  builder covering SELECT/INSERT/UPDATE/DELETE + WHERE / nested
  AND/OR groups (`whereGroup` / `orWhereGroup`) + INNER/LEFT/RIGHT/CROSS
  JOIN with aliased `TableRef` + ORDER BY + LIMIT/OFFSET. For unions,
  subqueries, window functions, CTEs or aggregations beyond `count()`,
  drop to the underlying PDO connection.
- **No HTTP client.** Use Guzzle directly.
- **No template engine.** Plain PHP via `View::render`.
- **Sessions: thin wrapper, no auth.** `Cloude\Session` covers
  start/get/set/flash/CSRF over `$_SESSION` with hardened cookie
  defaults; it's NOT an auth system. Roll user / role / permission
  models in the app (or before `$router->dispatch()`). For MCP,
  validate API keys inside the handler.
- **No SSE or stdio MCP transport.** HTTP only.
- **No JSON Schema features beyond the listed subset** (`type`,
  `required`, `properties`, `additionalProperties`, `enum`, `items`,
  `min/maxItems`, `min/maximum`, `min/maxLength`, `pattern`). No
  `$ref`, no `oneOf`. Use `opis/json-schema` directly if you need them.
