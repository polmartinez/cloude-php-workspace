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
└── tests/                     ← Cloude\Testing — run with `vendor/bin/cloude-test`
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

### 3. `app/config/app.php`

```php
<?php
return [
    'base_url' => \Cloude\Config::env('BASE_URL'),   // null → auto-detect
    'debug'    => \Cloude\Config::boolEnv('DEBUG'),
    'paths' => [
        'data'  => BASEPATH . '/data',
        'views' => APPPATH . '/../views',  // or wherever you put templates
    ],
];
```

Add as many config files as you want next to this one (`db.php`,
`mail.php`, …); each is loaded on demand by `Config::load(...)`.

### 4. `www/index.php`

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
    return false;
}

\Cloude\Bootstrap::run();   // debug + views come from Config

$router = new \Cloude\Router(\Cloude\Config::baseUrl(['localhost', 'example.com']));
\App\Routes::register($router);
$router->dispatch();
```

The framework only relies on three directory constants (`DOCROOT`,
`APPPATH`, `BASEPATH`), defined by `Bootstrap::initPaths()`.
Everything else (base URL, debug flag, data/views paths, DB, mail, …)
goes through `Config::get(...)` / `Config::baseUrl()` / `Config::debug()`
/ `Config::path(...)` / `Config::timezone()`. The legacy `defineBaseUrl()` / `defineDebug()` +
ad-hoc `DATA_DIR` style still works for back-compat but new code
should be Config-driven.

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

## The Model IS the schema definition

When a feature touches relational data, declare the model first. The
subclass becomes the single place that describes the table's shape;
every cast, mass-assignment check, query, and DDL emitter reads from
it.

```php
class User extends \Cloude\Model\Model
{
    protected static string $table      = 'users';
    protected static string $primaryKey = 'id';

    // (1) Allowed fields — mass-assignment whitelist.
    protected static array $properties = ['id', 'email', 'name', 'role_id', 'created_at', 'status'];

    // (2) PHP-side types — coerced on read/write. NULL stays NULL.
    protected static array $types = [
        'id'         => 'int',
        'created_at' => 'datetime',
        'status'     => 'enum:' . Status::class,
    ];

    // (3) Indexes — the FW emits the SQL; another script applies it.
    protected static array $indexes = [
        ['type' => 'unique', 'columns' => ['email']],
    ];

    // (4) Foreign keys — same idea, with optional ON DELETE / ON UPDATE.
    protected static array $foreignKeys = [
        ['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'],
         'on_delete' => 'set null'],
    ];
}
```

| Property       | Effect                                                                                                 |
|----------------|--------------------------------------------------------------------------------------------------------|
| `$properties`  | Rejects unknown keys on `fill()` / `create()` (when non-empty). Hardens against untrusted-input        |
| `$types`       | Per-attribute coercion via `Cast::read` (storage→PHP) and `Cast::write` (PHP→storage). **NULL passes through untouched** |
| `$indexes`     | `User::indexesSql()` returns `list<string>` of `CREATE [UNIQUE] INDEX` statements                      |
| `$foreignKeys` | `User::foreignKeysSql()` returns `list<string>` of `ALTER TABLE … ADD CONSTRAINT` statements           |

`$indexes` and `$foreignKeys` are **metadata-only**. The framework
emits the SQL on demand and stops there — no cascade-from-PHP, no
eager loading, no FK-aware `delete()`. Referential integrity comes
from the database (assuming you applied the emitted SQL); the model
just declares the contract for documentation + emission. Feed the
strings to `pdo->exec()` from a migration step, an install task, or
whatever fits your project. Column SQL types (`VARCHAR(255)`, etc.)
live in your migrations, not in `$types`.

```php
// In an install / migration step:
foreach (User::indexesSql()     as $sql) $pdo->exec($sql);
foreach (User::foreignKeysSql() as $sql) $pdo->exec($sql);
```

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
| Nested WHERE groups (mix AND/OR with parens) | `$q->where(...)->whereGroup(fn ($g) => $g->where(...)->orWhere(...))->orWhereGroup(...)` | [`src/Storage/Query.php`](src/Storage/Query.php) |
| JOIN two tables | `$q->join('orders', 'orders.user_id', '=', 'users.id')` (also `leftJoin`/`rightJoin`/`crossJoin`) | Columns may be qualified (`'users.email'`) — they're quoted automatically |
| Reference table / column statically | `User::table()` / `User::field('email')` / `User::as('u')->field('email')` | Returns plain dotted strings; pair `User::as('u')` with `$q->from()` / `$q->join()` for typed joins |
| Alias a column in `select()` (preferred) | `$q->select('id', ['name', 'type_name'])`, `User::alias('name', 'user_name')`, `$u->alias('name', 'who')` | Tuple `[col, alias]` is the recommended form. Legacy `'name AS alias'` string still accepted for back-compat |
| Catch a SQL failure portably | `catch (\Cloude\Storage\StorageException $e)` (or its subclasses) | Every Query / PdoStorage execution wraps `PDOException`. Subclasses: `TableNotFoundException`, `ColumnNotFoundException`, `DuplicateKeyException`, `IntegrityConstraintException`, `ConnectionException`, `SyntaxErrorException`. `$e->sql / $e->bindings / $e->sqlState` are public readonly |
| Cast Model attributes (typed read/write) | `protected static array $types = ['age' => 'int', 'price' => 'decimal:2', 'tags' => 'json', 'created_at' => 'datetime', 'status' => 'enum:' . Status::class]` | Optional. Applied at hydrate (storage → PHP) and save (PHP → storage). Null always passes through. Catalogue: `int`, `float`, `string`, `bool`, `decimal[:N]`, `json`/`array`, `datetime[:FMT]`, `date[:FMT]`, `enum:FQCN`. See [`src/Model/Cast.php`](src/Model/Cast.php) |
| Manipulate dates / times | `\Cloude\DateTime::now()->addDays(7)->endOfDay()`, `$d->isPast()`, `$d->diffForHumans()`, `$d->toDateString()` | `Cloude\DateTime` extends `\DateTimeImmutable` — drop-in for any `\DateTimeInterface` consumer. The `datetime` cast hydrates straight into this class so the helpers are available on Model attributes |
| Freeze time in a test | `$this->freezeTime('2026-05-18 12:00:00')` (inside `Cloude\Testing\TestCase`) or `DateTime::setTestNow($when)` | Released automatically in `tearDown()` so leaks fail the next test instead of polluting the suite |
| Domain value object | `final class Money extends \Cloude\Domain\ValueObject { public function __construct(public readonly int $cents, public readonly string $ccy) {…} public function __toString(): string {…} }` | Structural `equals()` comes for free. Throw `\Cloude\Domain\DomainException` from the constructor to enforce invariants |
| Domain aggregate w/ events | `class Book extends \Cloude\Domain\AggregateRoot` — call `$this->recordEvent(new BookBorrowed(...))` inside domain methods; application code calls `$book->pullDomainEvents()` after persistence | `Cloude\Domain\DomainEvent` is the marker interface. No event bus shipped — handle the returned list however your app wants |
| Test base class | `class FooTest extends \Cloude\Testing\TestCase` — provides `useArrayModel()`, `useSqliteModel()`, `useMockModel()`, `captureHttp()`, `assertJsonResponse()`, `assertHttpException()`, `freezeTime()`, `assertModelHas()`. Run with `vendor/bin/cloude-test` |
| Mock a Model and assert calls | `$s = $this->useMockModel(User::class, $rows); /* run code */; $this->assertModelReceived($s, 'delete', times: 1); $s->lastCall('update')` | `Cloude\Testing\MockStorage` — recording wrapper on `ArrayStorage`. For `Model::query()` (SQL builder) tests, use `useSqliteModel()` — faking the builder is brittle |
| Multi-env config (dev / prod / anything) | `Config::configure($path, $env)` + `Config::get('db.default.dsn')` | [`examples/recipes/config.php`](examples/recipes/config.php), [`tests/ConfigTest.php`](tests/ConfigTest.php) |
| Send email (SMTP or sendmail) | `Mailer::forge()->send([...])` (reads `Config::get('email')`; FW defaults at `config/email.php` deep-merged under your `app/config/email.php`) | [`examples/recipes/mail.php`](examples/recipes/mail.php), [`examples/recipes/config/email.php`](examples/recipes/config/email.php), [`src/Mail/`](src/Mail/) |
| DKIM-sign outbound mail | Drop a `'dkim'` block in `app/config/email.php` (domain / selector / private_key); every `send()` signs automatically | [`src/Mail/DkimSigner.php`](src/Mail/DkimSigner.php) — relaxed/relaxed canon + RSA-SHA256 |
| Emit CREATE TABLE SQL | `Schema::createTableSql($table, $columns, $indexes, $foreignKeys, 'mysql')` | [`src/Storage/Schema.php`](src/Storage/Schema.php) — standalone helper. Supports `mysql` + `pgsql`. Not a migration runner — feed the output to phinx or pdo->exec() |
| Emit indexes / FKs from a model | `User::indexesSql()` / `User::foreignKeysSql()` — return `list<string>` ready for `pdo->exec()` | Declare `protected static array $indexes / $foreignKeys` on the subclass; columns live in your migrations |
| Sessions | `Session::start()` then `set/get/forget/all`; flash with `flash/pullFlash`; CSRF via `csrfToken/checkCsrf`; rotate ID on login with `regenerate()` | [`src/Session.php`](src/Session.php) — hardened cookie defaults, opt-in (not auto-started in Bootstrap) |
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
| Throw a 404 from a controller / repo | `throw new \Cloude\Http\NotFoundException("book $isbn")` | [`src/Http/NotFoundException.php`](src/Http/NotFoundException.php) — caught by `ErrorHandler`, renders bundled `404.html.php` (override under `viewBase`) |
| Throw any HTTP status | `throw new \Cloude\Http\HttpException(403, 'forbidden')` | [`src/Http/HttpException.php`](src/Http/HttpException.php) |
| File log w/ daily rotation | `new Logger($path, minLevel: 'info')` | `README.md → Logger` |

## What NOT to ask Claude to build

The framework is intentionally minimal. If you're tempted to ask
for any of these, **stop and reconsider** — they're absent on purpose:

- A DI container / service locator → wire by hand in `Routes.php`
- A full ORM (relations, observers, migrations) → `Cloude\Model` +
  `Cloude\Storage\Query` cover CRUD, basic joins, nested WHERE groups
  and aliases. For unions / subqueries / window functions / CTEs drop
  down to PDO; for migrations use `phinx` or `doctrine/migrations`
  directly
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
4. **Whether to add a test** — drop a `Cloude\Testing\TestCase` subclass under `tests/` if so.

## Testing & lint

```bash
composer test                              # Cloude\Testing runner — equivalent to `vendor/bin/cloude-test`
composer test -- --filter=Cast             # run only tests matching /Cast/
composer test -- tests/Storage             # scope to a directory
composer cs-check                          # php-cs-fixer dry-run
composer cs-fix                            # apply fixes
```

Tests live under `tests/`. Use namespace `Cloude\Tests\` (or your own
`App\Tests\` in consumer projects — just map it in `composer.json`).
Each test class extends `Cloude\Testing\TestCase`; method names start
with `test`. No `phpunit.xml.dist` — the runner discovers `*Test.php`
files under any path passed on the CLI (default `tests/`).

## Deployment

The app is a plain front-controller PHP web app — it runs identically
under `php -S`, Apache, nginx + PHP-FPM, Caddy, Heroku, Fly.io, or
anything else that speaks PHP 8.3+. The bundled examples deliberately
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
