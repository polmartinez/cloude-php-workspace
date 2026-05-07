# Cloude Framework

A minimalist PHP micro-framework. No magic, no service container, no database. Designed for small and medium web projects running on Apache + PHP.

- **PHP 8.3+**
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
    Config.php
    EventLog.php
    JsonFile.php
    Http/
      Cache.php
      AssetUrl.php
      ErrorHandler.php
    views/               # Default 500 / 500-debug views (overridable)
  tests/                 # PHPUnit tests
  example/               # Runnable sample app (see example/README.md)
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
| `Cloude\Config` | Bootstrap helpers: `env()`, `boolEnv()`, `defineBaseUrl()`, `defineDebug()` |
| `Cloude\EventLog` | Fire-and-forget POST to a webhook for usage analytics |
| `Cloude\JsonFile` | Per-request cached, atomic-write helper for JSON files |
| `Cloude\Http\Cache` | HTTP cache headers (`ok`, `notFound`, `unavailable`) and `conditionalGet()` |
| `Cloude\Http\AssetUrl` | Versioned asset URLs (`/{mtime}/assets/...`) for cache-busting |
| `Cloude\Http\ErrorHandler` | Global 503 handler with HTML / JSON / .md negotiation, debug mode |

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
   git tag -a v0.3.0 -m "v0.3.0"
   git push origin v0.3.0
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
