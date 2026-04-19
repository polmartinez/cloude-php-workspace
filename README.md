# Cloude Framework

A minimalist PHP micro-framework. No magic, no service container, no database. Designed for small and medium web projects running on Apache + PHP.

- **PHP 8.3+**
- **PSR-4** autoloading, namespace `Cloude\`
- **PSR-12 / PER-CS 2.0** coding style
- `declare(strict_types=1)` everywhere
- Zero required runtime dependencies (Parsedown and URLify are optional)

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
| `Cloude\Input` | Wrapper over `$_GET`, `$_POST`, `$_SERVER`, raw body and JSON |
| `Cloude\View` | Plain PHP template rendering with variable extraction and HTML escape |
| `Cloude\Markdown` | Markdown parser with YAML frontmatter (requires `erusev/parsedown`) |
| `Cloude\Str` | Utilities: `upTo()`, `truncate()`, `slug()` |

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

### `Cloude\Str`

```php
Str::upTo('hello world', ' ');       // 'hello'
Str::truncate('long text', 4);        // 'long...'
Str::slug('Hello World');             // 'hello-world'
```

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
   git tag -a v0.1.0 -m "Initial release"
   git push origin v0.1.0
   ```

After publication, any project can install it with:

```bash
composer require cloude/framework
```

## Philosophy

- **No magic**: the code you read is the code that runs. No generators, annotations or proxies.
- **Small classes**: each class fits in a file you can read in one sitting.
- **No required dependencies**: the core pulls nothing in. Parsedown and URLify are optional.
- **No global state**: no container, no singletons. Static classes are just namespaces for functions.

## License

MIT - see [LICENSE](LICENSE).
