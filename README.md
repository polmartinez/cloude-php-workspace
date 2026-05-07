# Cloude Framework

A minimalist PHP micro-framework. No magic, no service container, no database. Designed for small and medium web projects running on Apache + PHP.

- **PHP 8.4+**
- **PSR-4** autoloading, namespace `Cloude\`
- **PSR-12 / PER-CS 2.0** coding style
- `declare(strict_types=1)` everywhere
- Zero runtime dependencies. Markdown rendering and slug transliteration are
  done in-house. `ext-intl` improves slug quality when present, otherwise an
  iconv-based fallback is used.

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
    Bootstrap.php        # One-call front-controller bootstrap
    Cli.php              # Argv parsing + colored output for app/cli/ scripts
    Config.php
    EventLog.php
    Format.php           # Yaml / json / xml / markdown encode-decode dispatcher
    JsonFile.php
    JsonSchema.php       # In-house JSON Schema subset validator
    Logger.php           # File-backed logger with daily rotation
    Http/
      Cache.php
      AssetUrl.php
      ErrorHandler.php
      Response.php
    Mcp/
      Server.php         # MCP (Model Context Protocol) server, HTTP transport
      JsonRpc.php        # JSON-RPC 2.0 + MCP error codes
      McpException.php
    views/               # Default 500 / 500-debug views (overridable)
  tests/                 # PHPUnit tests
  example/               # Runnable sample app (see example/README.md)
    recipes/             # Cookbook snippets for sitemap, JSON-LD, ...
  composer.json          # Package manifest (name: cloude/framework)
  phpunit.xml.dist
  .php-cs-fixer.dist.php
  LICENSE
  README.md
```

## Components

| Class | Responsibility |
|---|---|
| `Cloude\Router` | Router with `/{param}` patterns and `get/post/put/patch/delete/any` helpers |
| `Cloude\Input` | Wrapper over `$_GET`, `$_POST`, `$_SERVER`, raw body and JSON; `langPrefix()` for `/es`, `/en` URLs |
| `Cloude\View` | Plain PHP template rendering with variable extraction and HTML escape |
| `Cloude\Markdown` | Frontmatter + body parser. Body rendered via `Markdown\Parser` by default; swappable with `useParser()` |
| `Cloude\Markdown\Parser` | In-house markdown → HTML parser. No external dependency |
| `Cloude\Markdown\File` | Disk I/O for markdown with transparent gzip (`.md` + `.md.gz`) |
| `Cloude\Markdown\Server` | Serves a markdown file with 304 / canonical / gzip passthrough |
| `Cloude\Str` | Utilities: `upTo()`, `truncate()`, `slug()`, `ascii()` (uses `Transliterator` when available) |
| `Cloude\Bootstrap` | One-call front-controller bootstrap (cli-server passthrough + ob_start + ErrorHandler + view base) |
| `Cloude\Config` | Bootstrap helpers: `env()`, `boolEnv()`, `defineBaseUrl()`, `defineDebug()` |
| `Cloude\Cli` | Argv parsing + colored output for `app/cli/` scripts |
| `Cloude\EventLog` | Fire-and-forget POST to a webhook for usage analytics |
| `Cloude\Format` | Yaml / json / xml / markdown encode-decode dispatcher (string ↔ array) |
| `Cloude\JsonFile` | Per-request cached, atomic-write helper for JSON files |
| `Cloude\JsonSchema` | In-house JSON Schema subset validator (no external deps) |
| `Cloude\Logger` | File-backed logger with daily rotation and `debug/info/warn/error` |
| `Cloude\Mcp\Server` | MCP server (Model Context Protocol) over HTTP / JSON-RPC 2.0, with auto input validation |
| `Cloude\Http\Cache` | HTTP cache headers (`ok`, `notFound`, `unavailable`) and `conditionalGet()` |
| `Cloude\Http\AssetUrl` | Versioned asset URLs (`/{mtime}/assets/...`) for cache-busting |
| `Cloude\Http\ErrorHandler` | Global 503 handler with HTML / JSON / .md negotiation, debug mode |
| `Cloude\Http\Response` | One-call response helpers: `json()`, `html()`, `xml()`, `markdown()`, `redirect()`, `notFound()`, `noContent()` |

## Quick start

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Cloude\Input;
use Cloude\Router;
use Cloude\View;

View::setBasePath(__DIR__ . '/views');

$router = new Router();

$router->get('/', function (): void {
    View::render('home.php', ['title' => 'Hello']);
});

$router->get('/users/{id}', function (array $params): void {
    echo 'User #' . $params['id'];
});

$router->post('/api/echo', function (): void {
    header('Content-Type: application/json');
    echo json_encode(Input::json());
});

$router->setNotFound(function (): void {
    View::render('404.php');
});

$router->dispatch();
```

## Example project

A complete, ready-to-run project lives in [`example/`](example/):

```bash
cd example
composer install
php -S localhost:8000 -t www
```

Open <http://localhost:8000>.

The example ships:

- `example/www/index.php` - entry point and bootstrap
- `example/www/.htaccess` - Apache rewrite rules
- `example/app/config.php` - base configuration
- `example/app/routes.php` - route definitions
- `example/views/` - layout and pages

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

`{name}` segments are extracted into an associative array and passed as the first handler argument.

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

View::render('home.php', ['title' => 'Hello']);   // prints
$html = View::capture('home.php', $vars);         // returns a string
echo View::e($text);                               // HTML escape
```

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

Not supported (by design): tables, footnotes, definition lists, reference-style
links, setext headings, nested lists. If you need any of these, plug Parsedown
in via `Markdown::useParser()`.

```php
$html = \Cloude\Markdown\Parser::toHtml("# Hello\n\nFirst **paragraph**.");
```

### `Cloude\Str`

```php
Str::upTo('hello world', ' ');           // 'hello'
Str::truncate('long text', 4);           // 'long...'
Str::slug('Hello World');                // 'hello-world'
Str::ascii('Análisis Político');         // 'Analisis Politico'
Str::ascii('Москва');                    // 'Moskva'
```

`ascii()` transliterates without lowercasing or stripping punctuation —
useful for fuzzy matching and search indexes. For URL slugs use `slug()`.

### `Cloude\Bootstrap`

Folds the canonical front-controller boilerplate (cli-server static-file
passthrough + `ob_start()` + `ErrorHandler::register()` + `View::setBasePath`)
into one call.

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/config.php';

if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: dirname(__DIR__) . '/app/views',
);

$router = new \Cloude\Router(BASE_URL);
// ...routes...
$router->dispatch();
```

`serveStaticIfExists()` returns `true` only under the PHP built-in dev server
when the request hits a real file inside `$docroot`. The caller MUST
`return false;` from the router script in that case (cli-server's documented
convention to delegate to its static handler). Production Apache uses
`.htaccess`, so this is a no-op there.

If `$viewBase` is omitted, `Bootstrap::run()` reads `View::getBasePath()` as a
fallback — handy when the project sets the view base in `app/config.php`.

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

Bootstrap helpers for `app/config.php`. Reads from `$_ENV / $_SERVER / getenv()`.

```php
Config::env('OPENAI_API_KEY');                 // ?string
Config::boolEnv('DEBUG', false);               // bool

Config::defineBaseUrl([                        // → defines BASE_URL
    'www.example.com',
    'example.com',
    'localhost',
]);
Config::defineDebug();                         // → defines DEBUG
```

`defineBaseUrl()` validates `$_SERVER['HTTP_HOST']` against the allowlist
(matching hostname only, port preserved) to prevent host-header injection.
A non-allowed host falls back to `localhost`.

### `Cloude\Http\ErrorHandler`

Drop-in 503 handler. Treats unhandled exceptions as temporary unavailability
(crawlers retry instead of deindexing). Negotiates HTML / JSON / `.md`
based on `Accept` and URL extension.

```php
ob_start();
\Cloude\Http\ErrorHandler::register(
    debug:    DEBUG,
    viewBase: dirname(__DIR__) . '/app/views', // optional override
);
```

In debug mode, HTML responses include source snippet and stack trace.
The HTML response uses `500.html.php` (or `500-debug.html.php` in debug);
if `viewBase` is set and the file exists there, it overrides the framework default.

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
// list arrays repeat the element. See example/recipes/sitemap.php.
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

See [`example/recipes/mcp.php`](example/recipes/mcp.php) for a runnable server.

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

## Recipes (cookbook snippets)

`example/recipes/` ships drop-in snippets for common patterns the framework
deliberately doesn't wrap in a class:

| Recipe | What it does |
|---|---|
| [`sitemap.php`](example/recipes/sitemap.php) | XML sitemap (and sitemap index) using `Format::xml` + `Http\Response::xml` |
| [`jsonld.php`](example/recipes/jsonld.php) | Schema.org JSON-LD blocks (Article, BreadcrumbList, FAQPage) using `Format::json` |
| [`mcp.php`](example/recipes/mcp.php) | Tiny MCP server with two tools and a resource catalogue using `Mcp\Server` |

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
   git tag -a v0.7.0 -m "v0.7.0"
   git push origin v0.7.0
   ```

After publication, any project can install it with:

```bash
composer require cloude/framework
```

## Philosophy

- **No magic**: the code you read is the code that runs. No generators, annotations or proxies.
- **Small classes**: each class fits in a file you can read in one sitting.
- **No required dependencies**: the core pulls nothing in. `ext-intl` is recommended for slug transliteration but not required.
- **No global state**: no container, no singletons. Static classes are just namespaces for functions.

## License

MIT - see [LICENSE](LICENSE).
