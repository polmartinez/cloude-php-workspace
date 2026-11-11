# AGENTS.md — guide for AI coding agents

> Reference for AI agents (Claude Code, Cursor, Codex, …) writing code that
> **consumes** `cloude/framework`. If you're modifying the framework itself,
> follow the inline class docblocks and `composer cs-check` instead.

This file is the **navigable index** to the rest of the reference. Each
section below has its own file under [`docs/agents/`](docs/agents/) so
you can load only what you need — the surface area is small enough that
loading the whole reference is also fine.

## Sibling docs

- [`PATTERNS.md`](PATTERNS.md) — architecture pattern guidance (Transaction
  Script vs. MVC + Repository vs. DDD layered). Maps "what your app looks
  like" to "which example to copy".
- [`SETUP.md`](SETUP.md) — eight-step interview script for **brand-new
  projects**. When the user says "I want to start a new project on
  cloude/framework", read SETUP.md and walk them through it. Don't guess
  defaults silently; ask each question.
- [`README.md`](README.md) — per-class reference with full API surface
  and code examples for every helper.

## Mental model

Keep this whole list in context — everything else is a deeper dive on
one of these axioms.

- **One class per file. No magic. No DSL. No container.** What you read
  is what runs. Build a `Cloude\Router`, register routes, dispatch.
- **Stateless static utilities** for everything except where instance
  state is fundamental (`Logger`, `TaskRunner`, `Mcp\Server`,
  `AssetUrl` after `configure()`, `Markdown::useParser()`).
- **Files are the default storage model**: JSON and Markdown on disk,
  accessed via `Cloude\JsonFile`, `Cloude\Markdown\File` and the
  `Cloude\Data\*Repository` base classes.
- **Relational data is opt-in** via `Cloude\Model` — a thin Active
  Record over a `Storage` interface. Adapters: `PdoStorage` (MySQL /
  Postgres / SQLite), `JsonStorage` (one file per row), `ArrayStorage`
  (in-memory).
- **PSR-4 only**, namespace `Cloude\`. Consumer projects typically use
  namespace `App\` mapped to `app/classes/`.

## Reference index

| Section | What's there | When to read |
|---|---|---|
| [Bootstrapping](docs/agents/bootstrapping.md) | The three path constants (`DOCROOT` / `APPPATH` / `BASEPATH`), canonical `www/index.php`, canonical `app/config/app.php` | First time setting up a project; updating boot wiring |
| [Decision matrix](docs/agents/decision-matrix.md) | "I want to do X → use Y" — the full lookup table grouped by topic (HTTP, Config, Model/Storage, Sessions, Mail, Testing, …) | Whenever you're picking which helper to use. Random-access |
| [The Model IS the schema](docs/agents/model-schema.md) | `$table` / `$primaryKey` / `$properties` / `$types` / `$indexes` / `$foreignKeys` — what each declaration does, what's runtime vs. metadata-only | Defining or modifying a `Cloude\Model` subclass |
| [Databases](docs/agents/databases.md) | MySQL / SQLite / PostgreSQL: what the framework adapts per driver, what you must keep portable, pgsql DDL, testing against a real server | Writing queries, raw SQL fragments, DDL or DB tests; switching database |
| [Testing and PHP versions](docs/agents/testing.md) | Supported PHP (8.3 minimum, clean on 8.5) and what new code must avoid; running the suite, reading `F` / `E` / `S` / `Deprecations`; `TestCase` helpers and the full assertion list; shared-process pitfalls; tests that need a server | Writing or running any test; before declaring a change done; using a PHP feature you are not sure 8.3 has |
| [Idioms, anti-patterns, scope](docs/agents/conventions.md) | "How Cloude code is shaped" — patterns to reach for, patterns to avoid, what the framework deliberately won't grow | Before reinventing something; when stuck |
| [Recipes](docs/agents/recipes.md) | Index of copy-paste examples under `examples/recipes/` plus the full mini-app demos under `examples/` | Before writing equivalent code from scratch |

## When in doubt

1. **Read the class docblock.** Every class starts with a 5–15 line
   summary of its purpose, edge cases, and idioms.
2. **Check the [recipes](docs/agents/recipes.md)** for the use case
   you're building.
3. **[`README.md`](README.md)** has the per-class reference with code
   examples.
4. If you can't find a class for the task, the framework probably
   doesn't ship one — and that's deliberate. Write the small bit of
   plain PHP and move on.

## Recent additions (v2.12 → v2.23)

For an AI working from older knowledge of this framework: these public
surfaces exist, and a few behaviours changed. Call forms are in the
[decision matrix](docs/agents/decision-matrix.md); model details in
[model-schema](docs/agents/model-schema.md).

### Behaviour changes — read these first

| Since | What changed | What to do in code you write |
|---|---|---|
| v2.12.1 | `Str::truncate($s, $max)` never returns more than `$max` characters; the ellipsis counts | Pass the real limit; don't subtract 3 yourself |
| v2.13.0 | Query / storage bindings are typed from the PHP value (`int` → integer, `bool` → boolean, `null` → NULL, anything else string) | Pass numbers as `int`, not `'5'`, when comparing against aggregates (`havingRaw('COUNT(*) > ?', [5])`) |
| v2.18.0 | A `HEAD` request with no `HEAD` route runs the matching `GET` route | Don't register duplicate `HEAD` routes |
| v2.18.0 | Line breaks in mail header values (subject, reply-to, addresses, custom headers) are collapsed to a space | Pass form input straight to `Mailer::send()`; no sanitising of your own needed for headers |
| v2.18.0 | `Transaction` uses PDO's own transaction at the outer level | `$pdo->inTransaction()` is reliable; never call `$pdo->beginTransaction()` yourself inside `Transaction::run()` |
| v2.19.0 | Route parameters arrive percent-decoded (`/libro/caf%C3%A9` → `$p['slug'] === 'café'`) | Never call `urldecode()` / `rawurldecode()` on a route parameter |
| v2.20.0 | `$model->save()` on a loaded model sends only the columns that changed; with no change it makes no storage call | Don't rely on `save()` re-sending untouched columns. In tests, expect only changed columns in `MockStorage::lastCall('update')` |
| v2.23.2 | Tests must not call `Reflection*::setAccessible()` or `curl_close()` (deprecated in PHP 8.5); the runner reports deprecations in a block after the summary | Read private members with `ReflectionProperty::getValue()` / `ReflectionMethod::invoke()` directly. Treat a `Deprecations (n):` block as something to fix |
| v2.23.0 | `Model::upsert()` / `Query::upsert()` exist; `updateOrCreate()` is no longer the only "insert or update" | Use `upsert()` when a UNIQUE index or the PK covers the match columns (atomic, one statement); keep `updateOrCreate()` for the rest |
| v2.22.0 | `new Query($pdo, $table)` / `new PdoStorage($pdo, $table)` pick the identifier quote from the PDO driver | Don't pass a quote character; don't write backticks or `"` in SQL fragments you hand to `selectRaw` / `havingRaw` if the code must run on both MySQL and PostgreSQL |
| v2.21.0 | Automatic `Query::cache()` keys cover the connection too | Nothing — unless the `Query` was built on a PDO outside `Connection`'s pool: then pass `key:` |

### New surfaces

- **`Cloude\Cache\Cache`** (v2.12.0) — static cache façade:
  `get / put / has / forget / flush / remember`, `Cache::store('name')`.
  Drivers `array`, `file`, `redis` (`ext-redis`), `memcached`
  (`ext-memcached`), configured in `config/cache.php` with one
  top-level key per store plus `default`. **Off by default**
  (`'default' => false` → every call is a no-op) until the app names a
  driver. `Cache::remember('k', $ttl, fn () => $heavy())` is the
  pattern to reach for. Not to be confused with `Cloude\Http\Cache`
  (response headers).
- **`Query::cache($ttl, $key?, $store?)`** (v2.12.0) — caches every
  SELECT terminal (`get / first / count / value / pluck`). Automatic
  key: `sha1(connection + sql + bindings)`. Pass `key:` when you need
  to `Cache::forget()` it after a write.
  See [`examples/recipes/cache.php`](examples/recipes/cache.php).
- **`Query::groupBy / havingRaw / selectRaw`** (v2.13.0) —
  `selectRaw('COUNT(*)', 'n')` adds an unquoted expression,
  `havingRaw('COUNT(*) > ?', [10])` takes bound values, `count()` on a
  grouped query returns the number of groups.
- **`Bootstrap::setEnv($env)` / `Bootstrap::env()`** (v2.14.0) — names
  the environment; `config/<env>/*.php` deep-merges over the base
  config. Call before `Config::configure()`.
- **`Input::server($key?, $default?)`** (v2.14.1) — raw `$_SERVER`
  access. `Input::header('Content-Type')` works since v2.18.0.
  `Input::uri()` returns `/` for a malformed request URI instead of
  throwing (v2.17.1).
- **`Str::sub()` / `Str::len()`** (v2.14.2) — `mb_substr` / `mb_strlen`.
- **`config/session.php`** (v2.15.0 / v2.16.0) — `cookie_lifetime`,
  `cookie_samesite`, `cookie_secure`, `cookie_httponly`, `cookie_path`,
  `cookie_domain`, `gc_maxlifetime`, `name`, `save_path`, and
  `handler` (`files` | `redis` | `memcached`, each with its own block).
  `Session::*` picks it up on its lazy start; no code change. Set
  `gc_maxlifetime` together with `cookie_lifetime`.
- **`Http\Cache::ok($stimeout = 86400, $timeout = 0)`** — first
  argument is the CDN lifetime (`s-maxage`), second the browser's
  (`max-age`).
- **`Model::updateOrCreate($match, $values = [])`** (v2.17.0) — finds
  the row matching every `$match` pair and updates it with `$values`,
  or creates `$match + $values`. Not atomic: back it with a UNIQUE
  index and `Transaction::run()` when calls can race.
- **`Markdown::toSafeHtml($md)`** / `Parser::toHtml($md, safe: true)`
  — for text you did not write (comments, customer notes, model
  output). `Markdown::toHtml()` lets inline HTML through and is for
  your own content only. Italic inside bold renders (v2.17.2).
- **`$model->isDirty(?string $attribute)` / `$model->dirty()`**
  (v2.20.0) — whether, and what, `save()` would write.
- **`StorageException::execute($pdo, $sql, $params)`** — the one place
  to run hand-written SQL with the framework's error wrapping and
  typed binding. `$params` is a list for `?` or `name => value` for
  `:name` (v2.18.0).
- **`Connection::nameOf($pdo)`** (v2.21.0) — pool name of a PDO, or null.
- **PostgreSQL** (v2.22.0) — full detail in
  [databases](docs/agents/databases.md). A `pgsql:` DSN is all it takes. `Query`,
  `PdoStorage`, `Model`, `Transaction` and `Schema` adapt to the
  driver: `"`-quoted identifiers, generated keys through `RETURNING`
  (`$q->insert($data, returning: 'col')`; default column `id`),
  `LIMIT` on UPDATE / DELETE, booleans into `BOOLEAN` or integer
  columns, `ILIKE`. `Schema::createTableSql(..., 'pgsql')` emits
  identity columns, PostgreSQL type names and trailing
  `CREATE INDEX` / `COMMENT ON` statements in one string.
- **`$this->markTestSkipped($reason)`** (v2.22.0) — on
  `Cloude\Testing\TestCase`; the runner reports `Skipped: n`.
- **Native upsert** (v2.23.0) — `$q->upsert($row, $conflictColumns,
  $updateColumns = null)` and `Model::upsert($match, $values = [],
  update: null)`: a single `INSERT … ON CONFLICT DO UPDATE`
  (`ON DUPLICATE KEY UPDATE` on MySQL). The conflict columns must be a
  UNIQUE index or the primary key. Prefer it to `updateOrCreate()`
  when that index exists. Rules in
  [databases](docs/agents/databases.md#native-upsert).
- **PHP 8.3 → 8.5** (v2.23.2) — minimum stays 8.3; framework and tests
  raise no deprecation on 8.5. The test runner lists deprecation
  notices after the summary instead of printing them mid-run. Version
  rules for new code and how to read the runner's output:
  [testing](docs/agents/testing.md).
- **CI** (v2.24.0) — `.github/workflows/tests.yml`: PHP 8.3 / 8.4 / 8.5
  matrix with PostgreSQL 16 and MySQL 8.4 services, plus a code-style
  job. The runner gained `--fail-on-deprecation`. See
  [testing](docs/agents/testing.md#continuous-integration).
