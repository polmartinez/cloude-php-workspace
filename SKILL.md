---
name: skill-cloude-framework
description: How to write efficient, idiomatic code for the Cloude PHP micro-framework (cloude/framework, `Cloude\` namespace). Use this WHENEVER the project includes `cloude/framework` in its `composer.json`, imports classes from the `Cloude\` namespace (Router, Bootstrap, View, Input, Http\Response, Markdown, Data\JsonRepository, Data\MarkdownRepository, Mcp\Server, TaskRunner, Cli, Logger, Collection, Arr, Str, Format, JsonFile, JsonSchema, Config, EventLog, AssetUrl, Cache, ErrorHandler), or whenever the user mentions "Cloude", "cloude-php-workspace", wants to build a minimalist PHP 8.4+ front controller, an MCP server over HTTP/JSON-RPC, "directory of files" repositories (one .json or .md per entity), a JSON API without Laravel/Symfony, an Artisan-lite CLI task runner, or anything based on the repo's `example/` app. Apply it even if the user only says "minimalist PHP" or "no magic" but the project context is Cloude. Do NOT use it for Laravel, Symfony or heavy DI-container frameworks.
---

# Cloude Framework — Quick guide for writing efficient code

Cloude is a minimalist PHP 8.4+ micro-framework: **one class per file, no magic, no container, no DSL, no full ORM**. PSR-4 (`Cloude\`), PSR-12 / PER-CS 2.0, `declare(strict_types=1)` in every file. Runtime dependencies: zero (only `ext-intl` recommended for non-Latin slug transliteration).

> **Rule #1 before writing any code**: if you find yourself reaching for "a service", "a container", "a middleware stack" or "a factory" — stop. Cloude doesn't have them on purpose. Solve it with the function or static class that already exists.

## When NOT to use this skill

- Laravel / Symfony / CodeIgniter / CakePHP project → use that framework's skill.
- WordPress or any CMS → not applicable.
- Plain PHP without a framework → use `skill-php-8`.

## Non-negotiable rules for Cloude code

1. `declare(strict_types=1);` on the first line of **every** PHP file in the project.
2. **One class per file.** The project namespace is usually `App\` mapped to `app/classes/`.
3. **Mandatory type hints** on every parameter, return and property. No `mixed` unless genuinely polymorphic (JSON decoders, etc.).
4. **Don't invent wrappers** around framework classes. If `Http\Response::json()` does what you need, use it — don't roll your own `header() + echo json_encode()`.
5. **Validate at the edge**: in MCP, `Mcp\Server` validates via `inputSchema`; in HTTP routes, call `JsonSchema::validate($input, $schema)` as early as possible and respond 422 on failure.
6. **No DI container**. Instance state only where it's fundamental (`Logger`, `TaskRunner`, `Mcp\Server`, `AssetUrl` after `configure()`, `Markdown::useParser()`). Everything else is static.
7. **Composer PSR-4**. Don't write custom autoloaders. Don't use `class_alias()` to bridge legacy global names — migrate the call sites with `use` and move on.
8. **Coding style**: `composer cs-check` must pass (PSR-12 / PER-CS 2.0). Use snake_case only in JSON arrays; properties and methods are `camelCase`, classes are `PascalCase`.

## Canonical project layout

```
my-app/
├── www/
│   ├── index.php          ← front controller (template below)
│   └── .htaccess          ← Apache rewrite rules
├── app/
│   ├── config.php         ← defines BASE_URL, DEBUG, DATA_DIR
│   ├── routes.php         ← (optional) router registrations
│   ├── classes/           ← App\ namespace, PSR-4
│   ├── cli/               ← command-line scripts
│   └── views/             ← plain PHP templates
├── data/                  ← JSON / Markdown per entity
├── tests/                    ← Cloude\Testing — run with `vendor/bin/cloude-test`
└── composer.json
```

## Canonical front controller (`www/index.php`)

Memorize it. Do NOT reinvent it. Do NOT add `ob_start()` or `ErrorHandler::register()` by hand — `Bootstrap::run()` already does that.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config.php';   // defines BASE_URL, DEBUG, DATA_DIR

// PHP built-in server static-file passthrough
if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: __DIR__ . '/../app/views',
);

$router = new \Cloude\Router(BASE_URL);
require __DIR__ . '/../app/routes.php';  // or register routes inline
$router->dispatch();
```

## Canonical `app/config.php`

```php
<?php
declare(strict_types=1);

\Cloude\Config::defineBaseUrl([
    'www.example.com',
    'example.com',
    'localhost',
]);
\Cloude\Config::defineDebug();  // defines DEBUG from ENV `DEBUG`

if (!defined('DATA_DIR')) {
    define('DATA_DIR', dirname(__DIR__) . '/data');
}
```

> `defineBaseUrl()` validates `HTTP_HOST` against an allowlist to prevent host-header injection. Non-allowed hosts fall back to `localhost`. **Don't skip this** and don't list hosts dynamically.

## Decision matrix — which class for which task

| You want to… | Use | Notes |
|---|---|---|
| Send a JSON response | `Http\Response::json($data, $status, $pretty)` | NEVER `header()` + `echo json_encode()` by hand |
| 404 / redirect / 204 | `Response::notFound()`, `redirect()`, `noContent()` | `redirect()` already strips CRLF to prevent injection |
| HTML / XML / Markdown / empty 200 | `Response::html/xml/markdown` | |
| Cache a 200 at the CDN | `Http\Cache::ok($seconds)` | Sets both `Cache-Control` and `CDN-Cache-Control` |
| Conditional GET (304) | `Cache::conditionalGet(filemtime($path))` | `true` = already sent 304, exit the route |
| Read JSON (per-request cached) | `JsonFile::read($path)` or `readOr($path, [])` | `null` on missing/invalid |
| Atomic JSON write | `JsonFile::write($path, $data, pretty: false)` | Temp + rename |
| Generic encode/decode | `Format::json/yaml/xml/markdown` | Dispatches `string ↔ array` |
| Validate JSON Schema | `JsonSchema::validate($data, $schema)` | Errors list; `[]` = valid |
| Slug / transliteration | `Str::slug($s)` / `Str::ascii($s)` | `ext-intl` for non-Latin |
| Random / UUID / hash | `Str::random()`, `Str::uuid()`, `Str::hash()` | `random_bytes` internally |
| Case conversion | `Str::camel/pascal/snake/kebab` | |
| Mask PII | `Str::mask('+34600123456', '*', 4, -3)` | Negative length keeps a tail visible |
| Dot-path array access | `Arr::get/set/has/forget/pluck/dot/undot/merge` | |
| Data pipeline | `Collection::make($rows)->filter()->sortBy()->take()->pluck()->all()` | `ArrayAccess`, `Countable`, iterable |
| Directory of `.json` per entity | extend `Data\JsonRepository` | Override `transform($data, $slug)` |
| Directory of `.md` per entity | extend `Data\MarkdownRepository` | Reads `.md.gz` transparently |
| Markdown → HTML | `Markdown::toHtml($md)` | In-house parser. Only swap with `useParser()` if you need tables/footnotes |
| Frontmatter + body | `Markdown::parse($content)` | Returns `meta`, `html`, `paragraphs`, `description` |
| Serve a `.md` over HTTP | `Markdown\Server::serve($path, $canonicalUrl)` | 404 / 304 / canonical / gzip passthrough |
| MCP server (HTTP/JSON-RPC) | `new Mcp\Server(...)` + `tool()` + `resourceProvider()` + `resourceReader()` | Auto-validates `inputSchema` |
| CLI script | `Cli::parseArgs($argv)` + `flag/option/positional` + `info/warn/error/success/abort` | Colors only on TTY |
| Group CLI scripts | `TaskRunner::register()` / `registerClass()` | One `tasks.php` entry point with `prefix:method` dispatch |
| File log with daily rotation | `new Logger($path, minLevel: 'info')` | |
| Fire-and-forget webhook | `EventLog::send($payload)` | curl_multi in `register_shutdown_function` |
| Versioned asset URLs | `Http\AssetUrl::configure(...)` + `AssetUrl::get($rel)` | Requires Apache rewrite |
| Full bootstrap | `Bootstrap::run(debug:, viewBase:)` | `ob_start` + ErrorHandler + View in one call |

## Router — patterns you'll use constantly

```php
$router = new \Cloude\Router(BASE_URL);

// Basics
$router->get('/', fn () => \Cloude\View::render('home.php', ['title' => 'Hi']));
$router->post('/users', $createHandler);

// Parameters (passed as associative array to the first argument)
$router->get('/users/{id:\d+}', fn (array $p) => \Cloude\Http\Response::json(['id' => $p['id']]));
$router->get('/posts/{slug?}',  $showOrList);              // optional
$router->get('/{lang:(es|en)}/about', $aboutHandler);      // constrained

// Nestable groups
$router->group('/api/v1', function (\Cloude\Router $r) {
    $r->get('/parties',         $list);
    $r->get('/parties/{slug}',  $show);

    $r->group('/admin', function (\Cloude\Router $r) {
        $r->get('/stats', $stats);
    });
});

// Same route many methods / same handler many routes
$router->any('/health', fn () => \Cloude\Http\Response::noContent());
$router->add(['/foo', '/bar'], $sameHandler);

$router->setNotFound(fn () => \Cloude\View::render('404.php'));
$router->dispatch();
```

**Pattern syntax**:
- `{name}` — captures a segment (no slash).
- `{name?}` — optional, including the leading `/`.
- `{name:regex}` — constrained by regex.
- `{name?:regex}` — optional + constrained.

## Input

```php
use Cloude\Input;

Input::method();              // 'GET', 'POST', …
Input::uri();                 // path without query
Input::get('q');              // $_GET['q'] or null
Input::post('name');
Input::json();                // decodes JSON body to array
Input::body();                // raw
Input::header('User-Agent');
Input::ip(trustProxy: false); // watch out with trustProxy in prod
```

## JSON route handler with validation — template

Canonical pattern for a JSON API endpoint:

```php
$router->post('/api/v1/users', function (): void {
    $input = \Cloude\Input::json() ?? [];

    $schema = [
        'type' => 'object',
        'properties' => [
            'email' => ['type' => 'string', 'pattern' => '^.+@.+\..+$'],
            'name'  => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            'role'  => ['type' => 'string', 'enum' => ['admin', 'user']],
        ],
        'required' => ['email', 'name'],
        'additionalProperties' => false,
    ];

    $errors = \Cloude\JsonSchema::validate($input, $schema);
    if ($errors !== []) {
        \Cloude\Http\Response::json(['errors' => $errors], 422);
        return;
    }

    // …domain logic…
    \Cloude\Http\Response::json(['id' => $newId], 201);
});
```

## "Directory of files" repositories (core idiom)

Subclass `JsonRepository` or `MarkdownRepository`. Override `transform()` to normalize shape and attach the slug. Do NOT create trivial `findById` methods — `find($slug)` already exists.

```php
<?php
declare(strict_types=1);

namespace App\Repositories;

use Cloude\Collection;
use Cloude\Data\JsonRepository;

final class PartiesRepo extends JsonRepository
{
    public function __construct(string $country)
    {
        parent::__construct(DATA_DIR . "/scopes/{$country}/parties");
    }

    /** @return array<string, mixed> */
    protected function transform(array $data, string $slug): array
    {
        return [
            'slug'   => $slug,
            'name'   => $data['nombre']             ?? $slug,
            'family' => $data['familia_ideologica'] ?? null,
        ] + $data;
    }

    public function byFamily(string $family): Collection
    {
        return $this->all()->filter(fn (array $p): bool => $p['family'] === $family);
    }
}
```

Usage:

```php
$repo = new PartiesRepo('espana');
$repo->slugs();           // ['psoe', 'pp', …]
$repo->exists('psoe');    // bool
$repo->find('psoe');      // ?array
$repo->findOr('x', []);   // array
$repo->all();             // Cloude\Collection keyed by slug
$repo->byFamily('left');  // Collection
$repo->write('new', […]); // atomic
```

## Collection — data pipeline

The chainable pipeline that replaces nested loops. Ends in `all()` / `first()` / a scalar.

```php
use Cloude\Collection;

$top = Collection::make($users)
    ->filter(fn (array $u): bool => $u['active'])
    ->sortBy('score', descending: true)
    ->take(3)
    ->pluck('name', 'id')   // dot-paths supported: ->pluck('meta.title', '_slug')
    ->all();
```

**Chainable**: `map`, `filter`, `reject`, `pluck`, `keyBy`, `groupBy`, `sortBy`, `sort`, `reverse`, `take`, `slice`, `chunk`, `unique`, `values`, `keys`, `merge`, `each`.

**Terminals**: `all`, `count`, `isEmpty`, `isNotEmpty`, `first`, `last`, `contains`, `every`, `some`, `reduce`, `sum`, `avg`, `min`, `max`.

## MCP server — full template

```php
<?php
declare(strict_types=1);

use Cloude\Mcp\JsonRpc;
use Cloude\Mcp\McpException;
use Cloude\Mcp\Server;

$mcp = new Server(
    name:        'my-data',
    version:     '1.0',
    description: 'Public dataset over MCP.',
    endpoint:    BASE_URL . '/mcp',
);

$mcp->tool(
    name:        'echo',
    description: 'Echoes the message.',
    inputSchema: [
        'type' => 'object',
        'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['message'],
        'additionalProperties' => false,
    ],
    handler: function (array $args): array {
        if ($args['message'] === 'forbidden') {
            throw new McpException(JsonRpc::INVALID_PARAMS, 'forbidden message');
        }
        return ['content' => [['type' => 'text', 'text' => $args['message']]]];
    },
);

$mcp->resourceProvider(fn (): array => [
    ['uri' => 'mem://hi', 'name' => 'Hi', 'mimeType' => 'text/plain'],
]);
$mcp->resourceReader(fn (string $uri): ?array => $uri === 'mem://hi'
    ? ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => 'world']
    : null);

$router->get('/.well-known/mcp.json', fn () => $mcp->respondManifest());
$router->any(['/mcp', '/mcp-server'], fn () => $mcp->dispatch());
```

What `Mcp\Server` handles for you automatically: CORS + preflight, JSON-RPC parse/dispatch with correct error codes, `initialize`, `ping`, `tools/list`, `tools/call` (with auto validation against `inputSchema`), `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, the `/.well-known/mcp.json` manifest. For auth, wrap it in a route-level middleware — the server doesn't include it.

## CLI — `TaskRunner` for batch jobs

`app/cli/tasks.php`:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Cloude\Cli;
use Cloude\TaskRunner;

final class ContentTasks
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
$runner->register('ping', fn (): void => Cli::out('pong'), 'Connectivity check.');
$runner->registerClass('content', ContentTasks::class);

exit($runner->run($argv));
```

Shell usage:

```bash
php app/cli/tasks.php                                  # list every task
php app/cli/tasks.php help content:rebuild-index       # describe one
php app/cli/tasks.php content:rebuild-index --country=fr --dry-run
php app/cli/tasks.php content:purge-old --days=30
```

`registerClass()` walks the `public static` methods of the class. `rebuildIndex` is exposed as `content:rebuild-index` (kebab-cased). The first line of the docblock becomes the description shown in `list`.

Each handler receives the parsed `$args` array and returns `int` (exit code), `null`/`true`/`void` (exit 0), or `false` (exit 1).

## Views

Plain PHP, no template engine. `View::e()` escapes HTML — always use it for dynamic output.

```php
use Cloude\View;

// Setup (Bootstrap::run already does it via viewBase:)
View::setBasePath(__DIR__ . '/views');

// Inside a route
View::render('home.php', ['title' => 'Hello', 'user' => $user]);   // prints
$html = View::capture('home.php', $vars);                          // returns string
```

In the template:
```php
<!-- views/home.php -->
<h1><?= View::e($title) ?></h1>
<p>Hello, <?= View::e($user['name']) ?></p>
```

## Logger

```php
$log = new \Cloude\Logger('/var/log/myapp.log', minLevel: 'info');
$log->info('http request', ['path' => '/foo']);
$log->error('db unreachable', ['code' => 503]);
// → /var/log/myapp-2026-05-13.log with daily rotation by default
```

Levels: `debug` < `info` < `warn` < `error`. Pass `rotation: 'none'` to disable rotation.

## Anti-patterns — do NOT do these

1. ❌ Reinventing `Http\Response::json()` with `header('Content-Type: …'); echo json_encode($x);`
2. ❌ Looking for a DI container. It doesn't exist, on purpose.
3. ❌ Plugging Parsedown via `Markdown::useParser()` "just in case". Only if you need footnotes / reference links / definition lists.
4. ❌ Writing a custom autoloader. Composer PSR-4 is enough.
5. ❌ `class_alias()` to bridge legacy global names. Migrate call sites with `use App\Foo;`.
6. ❌ Wrapping an HTTP client. Cloude doesn't ship one. Use `guzzlehttp/guzzle` directly if needed.
7. ❌ Repository methods like `findById` that just wrap the base — `find($slug)` already exists.
8. ❌ Sprinkling `htmlspecialchars($x)` everywhere — use `View::e($x)`.
9. ❌ Custom `esc()` / `dd()` / `json_response()` helpers.
10. ❌ Calling `ob_start()` or `ErrorHandler::register()` after `Bootstrap::run()` — it already did.
11. ❌ Treating `_slug` as "internal": the default `transform()` attaches it so you can keep plucking/grouping on it.
12. ❌ Writing to a `JsonRepository` with your own `file_put_contents` — you lose atomic writes.

## What the framework deliberately does NOT include

If you need any of these, install a dedicated library — don't wait for Cloude to add it:

- **No full ORM**. `Cloude\Model` is a thin Active Record over a `Storage` interface (`PdoStorage`, `JsonStorage`, `ArrayStorage`). No migrations, no relations, no observers. For joins / subqueries / aggregates beyond `count()`, drop down to PDO directly.
- **No HTTP client**. Guzzle directly.
- **No template engine**. Plain PHP via `View::render`.
- **No session / auth helpers**. `$_SESSION` and a route-level check before `$router->dispatch()`. For MCP, validate keys inside the handler.
- **No SSE or stdio MCP transport**. HTTP only.
- **JSON Schema only the documented subset**: `type`, `required`, `properties`, `additionalProperties`, `enum`, `items`, `min/maxItems`, `min/maximum`, `min/maxLength`, `pattern`. No `$ref`, no `oneOf`. If you need them, `opis/json-schema`.

## Development workflow

```bash
composer install
composer test          # cloude-test (Cloude\Testing\Runner)
composer cs-check      # php-cs-fixer dry-run — MUST pass before commit
composer cs-fix        # apply fixes
```

Dev server (mirrors what Apache does in prod):
```bash
php -S localhost:8000 -t www
```

## When in doubt

1. Read the **class docblock** — each starts with 5–15 lines of purpose, edge cases and idioms.
2. Check **`vendor/cloude/framework/example/recipes/`** — `sitemap.php`, `jsonld.php`, `mcp.php`, `tasks.php`, `data.php` are drop-in copy-paste.
3. **`README.md`** has the per-class reference with examples.
4. If you can't find a class for the task, **the framework probably doesn't ship one on purpose**. Write the 10–20 lines of plain PHP and move on.

## Quick reference — all namespace classes

### `Cloude\` (core)
`Arr`, `Bootstrap`, `Cli`, `Collection`, `Config`, `EventLog`, `Format`, `Input`, `JsonFile`, `JsonSchema`, `Logger`, `Markdown`, `Router`, `Str`, `TaskRunner`, `View`.

### `Cloude\Http\`
`AssetUrl`, `Cache`, `ErrorHandler`, `Response`.

### `Cloude\Data\`
`Repository` (abstract), `JsonRepository`, `MarkdownRepository`.

### `Cloude\Markdown\`
`File`, `Parser`, `Server`.

### `Cloude\Mcp\`
`Server`, `JsonRpc`, `McpException`.

---

**Reference repo**: https://github.com/polmartinez/cloude-php-workspace — the `example/` is runnable, `AGENTS.md` is the source of truth on idioms. If this skill and `AGENTS.md` conflict, **`AGENTS.md` wins** (it's the author's).
