# CLAUDE.md — building apps with `cloude/framework` using Claude Code

> Auto-loaded by Claude Code when working inside this repo (or any
> project that copies this file to its root). Companion to
> [`AGENTS.md`](AGENTS.md) — that one is the **API reference**, this
> one is the **how-do-I-actually-ship-something** playbook.
>
> **For consumer projects**: copy this file from
> `vendor/cloude/framework/CLAUDE.md` into your project root and adjust
> the namespace / paths. Claude Code will pick it up there.
>
> **Starting a brand-new project from scratch?** Use [`SETUP.md`](SETUP.md)
> instead — it's an eight-step interview that scaffolds the project
> based on the user's answers (namespace, docroot, run mode, pattern,
> CSS / JS, optional modules). This file is for ongoing development
> once the skeleton exists.

## TL;DR for Claude

When asked to build something on top of `cloude/framework`:

1. **Read [`AGENTS.md`](AGENTS.md) first.** Every helper, with the call
   form. Don't re-invent `Response::json`, `JsonFile::write`, `Str::slug`, …
2. **Pick the right pattern.** [`PATTERNS.md`](PATTERNS.md) is the
   one-page decision guide — Transaction Script for ≤10 routes, MVC +
   Repository for CRUD apps, DDD only when the domain has invariants
   that need enforcing everywhere. Don't over-engineer.
3. **Find the closest example before writing new code:**
   - [`examples/basic/`](examples/basic/) — Transaction Script
   - [`examples/contacts/`](examples/contacts/) — MVC + Repository (form + JSON Schema + JS live search)
   - [`examples/library/`](examples/library/) — DDD (Domain / Application / Infrastructure / Presentation)
   - [`examples/recipes/`](examples/recipes/) — sitemap, JSON-LD, MCP, CLI tasks, repos, model, mail, markdown
4. **Stay inside the mental model**: no DI container, no magic.
   `Cloude\Model` is a thin Active Record (no relations, no observers,
   no migrations) — opt-in only. The bundled `Cloude\Db\Query` builder
   covers SELECT / INSERT / UPDATE / DELETE with WHERE / ORDER BY /
   LIMIT / OFFSET; joins and subqueries go straight to PDO. If
   something feels like it needs a "service locator" or a "repository
   factory", you're probably overcomplicating.
5. **Wire by hand** in `app/Routes.php` or `www/index.php`. That's the seam.

## Project layout (recommended)

```
my-app/
├── composer.json              ← requires cloude/framework, maps App\ → app/
├── www/                       ← document root
│   ├── index.php              ← front controller
│   └── assets/                ← static CSS/JS/images
├── app/
│   ├── config.php             ← BASE_URL, DEBUG, DATA_DIR
│   ├── Routes.php             ← route table + manual wiring
│   ├── Controller/            ← thin HTTP handlers
│   ├── Repository/            ← extends Cloude\Data\JsonRepository, etc.
│   └── Domain/                ← optional, for DDD-shaped projects
├── views/                     ← plain PHP templates, `.html.php` extension
│   └── layout.html.php        ← (double extension separates views from PHP code)
├── data/                      ← JSON / Markdown content (or use Cloude\Model for relational)
└── tests/                     ← PHPUnit
```

## From zero to "Hello, world" in 6 steps

### 1. Init

```bash
mkdir my-app && cd my-app
composer require cloude/framework
mkdir -p www/assets app views data
```

### 2. Tell Composer about the `App\` namespace

`composer.json`:

```json
{
    "require":  { "cloude/framework": "^0.15" },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

```bash
composer dump-autoload
```

### 3. `app/config.php`

```php
<?php
declare(strict_types=1);

\Cloude\Config::defineBaseUrl(['localhost', 'example.com']);
\Cloude\Config::defineDebug();

if (!defined('DATA_DIR')) {
    define('DATA_DIR', dirname(__DIR__) . '/data');
}
```

### 4. `www/index.php`

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/config.php';

if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: __DIR__ . '/../views',
);

$router = new \Cloude\Router();
\App\Routes::register($router);
$router->dispatch();
```

### 5. `app/Routes.php` + minimal views

```php
<?php
declare(strict_types=1);

namespace App;

use Cloude\Router;
use Cloude\View;

class Routes
{
    public static function register(Router $router): void
    {
        $router->get('/', fn () => View::render('layout.html.php', [
            'title'   => 'Hello',
            'content' => 'home.html.php',
        ]));
    }
}
```

View templates use the `.html.php` double extension by convention —
keeps them visually distinct from PHP source files (controllers,
models) at a glance and improves IDE highlighting.

```php
<?php // views/layout.html.php ?>
<?php use Cloude\View; ?>
<!doctype html>
<title><?= View::e($title) ?></title>
<?php require __DIR__ . '/' . $content; ?>
```

```php
<?php // views/home.html.php ?>
<h1>Hello, cloude/framework</h1>
```

### 6. Run

```bash
php -S localhost:8000 -t www
```

Open <http://localhost:8000>. That's the whole skeleton — about
40 lines of PHP across 4 files.

## Adding a feature: the standard workflow

Walk-through for `POST /api/leads` that validates input and writes one
JSON file per submission:

1. **Add the route** in `Routes.php` (`$router->post(...)`).
2. **Define a JSON Schema** for the input next to the handler.
3. **Validate** with `Cloude\JsonSchema::validate` — return
   `Response::json($errors, 422)` on failure.
4. **Persist** with `Cloude\JsonFile::write` (atomic, temp + rename)
   under `DATA_DIR/leads/{uuid}.json`. Mint the id with `Str::uuid()`.
5. **Respond** with `Response::json` (or `Response::redirect(..., 303)`
   for HTML forms).

```php
$router->post('/api/leads', function (): void {
    $input  = \Cloude\Input::json() ?? [];
    $errors = \Cloude\JsonSchema::validate($input, [
        'type'                 => 'object',
        'required'             => ['email'],
        'additionalProperties' => false,
        'properties'           => [
            'email' => ['type' => 'string', 'pattern' => '^[^@\s]+@[^@\s]+\.[^@\s]+$'],
            'name'  => ['type' => 'string', 'maxLength' => 80],
        ],
    ]);
    if ($errors !== []) {
        \Cloude\Http\Response::json(['errors' => $errors], 422);
        return;
    }

    $id = \Cloude\Str::uuid();
    \Cloude\JsonFile::write(
        DATA_DIR . '/leads/' . $id . '.json',
        $input + ['id' => $id, 'created_at' => gmdate('c')],
        pretty: true,
    );

    \Cloude\Http\Response::json(['id' => $id], 201);
});
```

The same pattern works for HTML forms — read `Input::post('field')`,
validate the same way, and `Response::redirect` on success.

## Common patterns at a glance

| You want… | Use | Closest reading |
|---|---|---|
| JSON list endpoint | `Response::json(['items' => $rows])` | [`examples/contacts/.../ContactsController::apiSearch`](examples/contacts/app/Controller/ContactsController.php) |
| HTML form + server validation | `Input::post` + `JsonSchema::validate` + re-render with errors | [`ContactsController::create`](examples/contacts/app/Controller/ContactsController.php) |
| File-per-entity storage (slug-keyed, schemaless) | extend `Data\JsonRepository`, override `transform()` | [`examples/recipes/data.php`](examples/recipes/data.php), [`ContactsRepo`](examples/contacts/app/Repository/ContactsRepo.php) |
| Markdown content | `Data\MarkdownRepository` + `Markdown\Server::serve` | [`examples/recipes/data.php`](examples/recipes/data.php) |
| Relational data (MySQL / Postgres / SQLite) | `class Foo extends Cloude\Model\Model` + `PdoStorage` | [`examples/recipes/model.php`](examples/recipes/model.php) |
| Same model, in-memory (tests) | `Cloude\Model\Storage\ArrayStorage` | [`tests/Model/ModelTest.php`](tests/Model/ModelTest.php) |
| Rich SQL queries (`>`, `<`, `LIKE`, `IN`, `BETWEEN`, `IS NULL`, multi-`ORDER BY`) | `User::query()->where(...)->orderBy(...)->limit(...)->get()` | [`src/Storage/Query.php`](src/Storage/Query.php), [`tests/Storage/QueryTest.php`](tests/Storage/QueryTest.php) |
| Multi-env config (dev / prod / anything) | `Config::configure($path, $env)` + `Config::get('db.default.dsn')` | [`examples/recipes/config.php`](examples/recipes/config.php), [`tests/ConfigTest.php`](tests/ConfigTest.php) |
| Send email (SMTP or sendmail) | `Mailer::forge()->send([...])` (reads `Config::get('mail')`) | [`examples/recipes/mail.php`](examples/recipes/mail.php), [`src/Mail/`](src/Mail/) |
| Named DB connection pool | `Connection::pdo('default')` reads `db.default` from config, caches per name | [`src/Storage/Connection.php`](src/Storage/Connection.php) |
| Inspect SQL with values inlined | `User::query()->where(...)->compile()` (debug only — never execute) | [`src/Storage/Query.php`](src/Storage/Query.php) |
| Live search box | JSON route + `fetch()` with debounce | [`examples/contacts/www/assets/app.js`](examples/contacts/www/assets/app.js) |
| MCP server | `new Mcp\Server(...)` + `tool()` | [`examples/recipes/mcp.php`](examples/recipes/mcp.php) |
| CLI cron / batch job | `TaskRunner::register / registerClass` | [`examples/recipes/tasks.php`](examples/recipes/tasks.php) |
| XML sitemap | `Format::xml($tree, pretty: true)` + `Response::xml` | [`examples/recipes/sitemap.php`](examples/recipes/sitemap.php) |
| JSON-LD on a page | array → `Format::json($graph, pretty: true)` | [`examples/recipes/jsonld.php`](examples/recipes/jsonld.php) |
| DDD layering | Domain interfaces + Cloude-backed adapters | [`examples/library/`](examples/library/) |
| Asset cache-busting | `Http\AssetUrl::configure()` once + `AssetUrl::get($rel)` in views | `README.md → AssetUrl` |
| Conditional GET (304) | `Cache::conditionalGet(filemtime($path))` | [`Markdown\Server::serve`](src/Markdown/Server.php) |
| File log w/ daily rotation | `new Logger($path, minLevel: 'info')` | `README.md → Logger` |

## What NOT to ask Claude to build

The framework is intentionally minimal. If you're tempted to ask
for any of these, **stop and reconsider** — they're absent on purpose:

- A DI container / service locator → wire by hand in `Routes.php`
- A full ORM (relations, observers, migrations) or a query builder
  with joins / unions / subqueries → `Cloude\Model` + `Cloude\Db\Query`
  cover the 80% case; for joins drop down to PDO; for migrations use
  `phinx` or `doctrine/migrations` directly
- A built-in HTTP client → use `guzzlehttp/guzzle` directly
- A template engine → plain PHP via `View::render` is the answer
- Session / auth helpers → `$_SESSION` + a check before `dispatch()`
- A custom autoloader → Composer PSR-4 is plenty
- Wrappers around `json_encode` / `header()` → `Response::json` already does it
- A "Sitemap" / "JSON-LD" / "Breadcrumbs" class → see the recipes; arrays + `Format::*` cover it

## Briefing Claude effectively

Bad: "Add a contact form."

Good: "Add a `POST /contact` route like the one in
[`examples/contacts/app/Controller/ContactsController::create`](examples/contacts/app/Controller/ContactsController.php).
Fields: name, email, message. Validate with `JsonSchema`. Persist as
`data/messages/{uuid}.json`. Return JSON `{id}` on success, render the
form again with errors on 422."

Things to put in the prompt:

1. **Closest example or recipe** — saves Claude 2-3 wrong guesses.
2. **The data shape** — JSON Schema or a field list, not just "a form".
3. **Where it should be wired** — `Routes.php`, a new controller, a new repo subclass.
4. **Whether to add a test** — drop a PHPUnit case under `tests/` if so.

## Testing & lint

```bash
composer test       # PHPUnit
composer cs-check   # php-cs-fixer dry-run
composer cs-fix     # apply fixes
```

Tests live under `tests/`. Use namespace `Cloude\Tests\` (or your own
`App\Tests\` mapped in `phpunit.xml.dist` for consumer projects).

## Deployment

The app is a plain front-controller PHP web app — it runs identically
under `php -S`, Apache, nginx + PHP-FPM, Caddy, Heroku, Fly.io, or
anything else that speaks PHP 8.4. The bundled examples deliberately
ship without any web-server config so the focus stays on what each
demo illustrates.

For run-it-now options (`php -S` and Docker one-liners) plus the
one-line rewrite recipe per server (Apache / nginx / Caddy), see
[`DEPLOYMENT.md`](DEPLOYMENT.md).

## Releasing a new version

For a fork of the framework or your own consumer package on Packagist:

```bash
git commit -am "feat: ..."
git tag -a v0.X.0 -m "v0.X.0 — short description"
git push origin main v0.X.0
```

Conventional commit prefixes used in this repo: `feat:`, `fix:`,
`docs:`, `breaking:`. Packagist webhooks pick up new tags automatically
once configured.

## When something genuinely doesn't fit

If the framework doesn't ship a class for what you need, that's
deliberate. **Write the small bit of plain PHP and move on.** Don't
fork the framework. Don't pull a heavyweight library "just in case".

Pattern: try it inline first. If the same plain-PHP block shows up in
three places, then promote it to `app/Support/Foo.php` (still inside
your project) — not into the framework.
