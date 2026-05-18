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

For **brand-new projects, scaffolded interactively**: [`SETUP.md`](SETUP.md)
is an eight-step interview script. When the user says "I want to start
a new project on cloude/framework", read SETUP.md and walk them
through it — namespace, docroot, run mode (`php -S` / Docker), pattern
(reads PATTERNS.md), CSS / JS choices, optional modules (storage,
mail, MCP). Don't guess defaults silently; ask each question.

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

**Philosophy (FuelPHP-style):** define a *fixed, minimal* set of
directory constants once, then route every other knob — base URL,
debug, data path, view path, db, mail, … — through `Cloude\Config`
files under `APPPATH/config/`.

### The three path constants

`Bootstrap::initPaths()` defines these once, before anything else:

| Constant   | Meaning                          | Typical value             |
|------------|----------------------------------|---------------------------|
| `DOCROOT`  | public web root (`www/`)         | `__DIR__` inside index.php |
| `APPPATH`  | application root (`app/`)        | `dirname(__DIR__) . '/app'` |
| `BASEPATH` | project root (parent of both)    | auto-derived (`dirname(APPPATH)`) |

Trailing slashes are stripped. If you pre-defined any of them (e.g. in
tests), `initPaths()` leaves the existing value alone.

### Canonical `www/index.php` (since v0.35)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\Cloude\Bootstrap::initPaths(
    docroot: __DIR__,
    apppath: dirname(__DIR__) . '/app',
);
\Cloude\Config::configure(APPPATH . '/config');

if (\Cloude\Bootstrap::serveStaticIfExists(DOCROOT)) {
    return false;  // dev-server static-file passthrough
}

\Cloude\Bootstrap::run();   // reads debug + views from Config

$router = new \Cloude\Router(\Cloude\Config::baseUrl(['example.com', 'localhost']));
// ...register routes...
$router->dispatch();
```

### Canonical `app/config/app.php`

```php
<?php
return [
    'base_url' => \Cloude\Config::env('BASE_URL'),    // null → auto-detect
    'debug'    => \Cloude\Config::boolEnv('DEBUG'),
    'paths' => [
        'data'  => BASEPATH . '/data',
        'views' => APPPATH . '/views',
    ],
];
```

Then anywhere in the app:

```php
\Cloude\Config::baseUrl(['example.com']);   // memoized, validated against allowlist
\Cloude\Config::debug();                    // bool
\Cloude\Config::path('data');               // app.paths.data
\Cloude\Config::get('db.default.dsn');      // any other config key
```

### Legacy bootstrap (still supported)

The previous global-constants approach (`defineBaseUrl()`,
`defineDebug()`, ad-hoc `DATA_DIR`) keeps working unchanged —
`Config::baseUrl()` / `Config::debug()` honor `BASE_URL` / `DEBUG`
when they're already defined as global constants. Migrate at your own
pace; **prefer the Config-driven approach in new code**.

## Decision matrix — when to use what

| You want to… | Use | Notes |
|---|---|---|
| Look up a project path | `Config::path('data')` / `Config::path('views')` | Reads `app.paths.{name}` — preferred over `DATA_DIR`-style globals |
| Resolve the base URL | `Config::baseUrl(['example.com'])` | Memoized; reads `app.base_url` → env → auto-detect |
| Read the debug flag | `Config::debug()` | Reads `app.debug` → env → false |
| Any other config value | `Config::get('db.default.dsn')` | Multi-env file loader, see [`Cloude\Config`](README.md#cloudeconfig) |
| Send a JSON response | `Http\Response::json($data, $status, $pretty)` | Don't `header()` + `echo json_encode()` by hand |
| 404 / redirect / 204 | `Response::notFound`, `redirect`, `noContent` | |
| Throw a 404 from anywhere | `throw new Http\NotFoundException("book $isbn")` | Caught by `ErrorHandler`; renders bundled `404.html.php` (HTML), JSON, or plain text |
| Throw any HTTP status | `throw new Http\HttpException(403, 'forbidden')` | Same as above; uses `500.html.php` template by default for non-404 |
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
| Build a SQL query | `User::query()->where('age', '>', 18)->orderBy('name')->get()` | `Cloude\Storage\Query` — SELECT/INSERT/UPDATE/DELETE + WHERE/JOIN/ORDER BY |
| Nested AND/OR predicates | `$q->where('active', 1)->whereGroup(fn ($g) => $g->where('role', 'admin')->orWhere('role', 'editor'))` | Use `orWhereGroup` for the OR-joined variant |
| INNER / LEFT / RIGHT / CROSS JOIN | `$q->leftJoin('orders', 'orders.user_id', '=', 'users.id')` | Columns may be `'table.col'` strings; quoted automatically |
| Static table / column references | `User::table()`, `User::field('email')`, `User::as('u')` | Avoid hand-writing `'users.email'` literals; pair `as()` with `Query::from()`/`join()` for typed joins |
| Declare a table's schema on the model | `protected static array $columns / $indexes / $foreignKeys` | Each entry uses the structured shape — see `Cloude\Storage\Schema` docblock. `User::createTableSql()` emits the full `CREATE TABLE` |
| Emit DDL for a model | `User::createTableSql('mysql')` or `User::dropTableSql()` | Dialects: `mysql` (default), `pgsql`. **Not a migration framework** — feed the SQL to `phinx` / `doctrine/migrations` / `pdo->exec()` as you like |
| Foreign key with ON DELETE / ON UPDATE | `['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'], 'on_delete' => 'set null', 'on_update' => 'cascade']` | Inside `$foreignKeys`. Actions: `cascade`, `set null`, `restrict`, `no action`, `set default` (case-insensitive) |
| Sessions | `Session::start()` then `set/get/has/forget/all`. Flash via `flash/pullFlash/reflash`. CSRF via `csrfToken/checkCsrf`. Auth flow: `regenerate()` after login | `Cloude\Session` — hardened defaults (`httponly`, `samesite=Lax`, `secure` on HTTPS). Opt-in: doesn't auto-start in `Bootstrap::run()` |
| Alias a column in SELECT | `$q->select('id', ['name', 'type_name'])` (preferred), or `User::alias('name', 'type_name')`, or `$u->alias('name', 'who')` | Each emits the `[column, alias]` tuple that `select()` accepts. Legacy `'name AS alias'` string still works |
| Catch a SQL error | `catch (\Cloude\Storage\StorageException $e)` | Subclasses: `TableNotFoundException`, `ColumnNotFoundException`, `DuplicateKeyException`, `IntegrityConstraintException`, `ConnectionException`, `SyntaxErrorException`. `$e->sql`, `$e->bindings`, `$e->sqlState` are public readonly. `getPrevious()` is the original `\PDOException` |
| Cast model attributes | `protected static array $casts = ['age' => 'int', 'price' => 'decimal:2', 'tags' => 'json', 'created_at' => 'datetime', 'status' => 'enum:' . Status::class]` | Applied on hydrate (read) and save (write); null passes through. See `Cloude\Model\Cast` for the type catalogue (`int`, `float`, `string`, `bool`, `decimal[:N]`, `json`/`array`, `datetime[:FMT]`, `date[:FMT]`, `enum:FQCN`) |
| Work with dates | `DateTime::now()`, `DateTime::parse('2026-05-18')`, `$d->addDays(7)->toDateString()`, `$d->isPast()`, `$d->diffForHumans()` | `Cloude\DateTime` extends `\DateTimeImmutable` with static constructors, format shortcuts, arithmetic, boundaries, comparisons, signed diff helpers. `datetime` cast returns this class so all helpers work on hydrated attributes |
| Freeze `now()` in tests | `DateTime::setTestNow($when)` / `clearTestNow()` (or `freezeTime()`/`unfreezeTime()` on `Cloude\Testing\TestCase`) | Carbon-style time travel for deterministic `isPast()` / `diffForHumans()` tests. `TestCase::setUp()` always clears between tests |
| DDD: value object base | `extends \Cloude\Domain\ValueObject` with `readonly` props + `__toString()`; gets structural `equals()` for free | Optional. Throw `Cloude\Domain\DomainException` from the constructor to enforce invariants at construction |
| DDD: aggregate root w/ events | `extends \Cloude\Domain\AggregateRoot`; call `recordEvent(new SomeEvent(...))` inside domain methods; application layer drains via `pullDomainEvents()` after persistence | `Cloude\Domain\DomainEvent` is the marker interface — implement it on plain readonly classes. Framework ships no event bus on purpose |
| DDD: domain invariant exception | `throw new \Cloude\Domain\DomainException("...")` | Extends `\DomainException`. Catch at the application boundary and translate to a user-friendly response |
| Write a test | `class FooTest extends \Cloude\Testing\TestCase`. Methods named `test*` are discovered. Lifecycle: `setUp()` / `tearDown()`. Assertions: `assertSame/True/False/Null/Count/InstanceOf/StringContainsString/Json/...` (PHPUnit-compatible names) |
| Run the tests | `vendor/bin/cloude-test` (or `composer test`). Filter: `--filter=Pattern`. Path scope: `cloude-test tests/Storage` |
| Parameterise a test | `#[\Cloude\Testing\DataProvider('cases')]` on the method + `public static function cases(): array { return ['label' => [arg1, arg2], ...]; }` |
| Expect an exception | `$this->expectException(SomeException::class)` (optional `expectExceptionMessage('substr')`). The runner verifies it was thrown |
| Cloude-specific helpers on TestCase | `useArrayModel()`, `useSqliteModel()`, `useMockModel()`, `captureHttp()`, `assertJsonResponse()`, `assertHttpException()`, `freezeTime()`, `assertModelHas()`, `assertModelReceived()` |
| Mock a Model's storage with call recording | `$store = $this->useMockModel(User::class, $rows); /* code under test */; $this->assertModelReceived($store, 'update', times: 1); $store->lastCall('update')` | `Cloude\Testing\MockStorage` wraps `ArrayStorage` and records every find / findBy / count / insert / update / delete call. For code that goes through `Model::query()`, use `useSqliteModel()` instead — faking the SQL builder leads to brittle tests |
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
| Send email (SMTP / sendmail) | `Mailer::forge()->send([...])` | Reads `app/config/email.php` via `Config::get('email')`. Framework ships defaults at `config/email.php`; app overrides key-by-key (deep-merge). AUTH LOGIN + STARTTLS for SMTP. See [`examples/recipes/config/email.php`](examples/recipes/config/email.php) for a copy-paste config |
| Sign outbound mail with DKIM | Add a `'dkim'` block in `app/config/email.php`: `'dkim' => ['domain' => '...', 'selector' => '...', 'private_key' => '/path/to/key.pem']`. Every send is signed automatically. | `Cloude\Mail\DkimSigner` — relaxed/relaxed canon + RSA-SHA256. Key source can be inline PEM, `env:VAR`, `file://path`, or a plain path. Publish `<selector>._domainkey.<domain>` TXT in DNS to complete the loop |
| Ship default configs from a library/module | `Cloude\Config::addPath('/path/to/your/config')`. The framework's own `config/email.php` is auto-loaded the same way | Resolution order is `[core, app, ...extra]` — last entry wins on every key (deep-merge via `Arr::merge`) |
| Versioned asset URLs (`/{mtime}/assets/…`) | `Http\AssetUrl::configure(...)` then `AssetUrl::get($rel)` | Apache rewrite required |

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
