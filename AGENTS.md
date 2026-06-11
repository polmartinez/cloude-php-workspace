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

## Recent additions (post-v2.11)

A reminder for AIs working off slightly stale knowledge that these
public surfaces exist. Full details in the
[decision matrix](docs/agents/decision-matrix.md).

- **`Cloude\Cache\Cache`** (v2.12.0) — static cache façade with four
  drivers: `array` (in-process), `file` (one file per key), `redis`
  (`ext-redis`), `memcached` (`ext-memcached`). Configure in
  `config/cache.php`. Disabled by default (`'default' => false`)
  until the app opts in. API: `get / put / has / forget / flush /
  remember`. `Cache::remember('k', $ttl, fn () => $heavy())` is the
  pattern to reach for.
- **`Query::cache($ttl, $key?, $store?)`** (v2.12.0) — opt-in caching
  on every SELECT terminal of `Cloude\Storage\Query`
  (`get / first / count / value / pluck`). Auto-key from
  `sha1(sql + bindings)`. See [`examples/recipes/cache.php`](examples/recipes/cache.php).
- **`Query::groupBy / havingRaw / selectRaw`** (v2.13.0) — GROUP BY,
  HAVING (raw expression with parameterized bindings), and aggregate
  SELECT columns. `count()` auto-wraps in a subquery when GROUP BY
  is set so it stays a scalar (returns number of groups).
- **`Cloude\Bootstrap::setEnv($env)` / `Bootstrap::env()` /
  `Bootstrap::$env`** (v2.14.0) — explicit application-environment
  selection. Drives the per-env config overlay (`config/production/`,
  `config/staging/`, ...) which deep-merges over the base configs.
  Call BEFORE `Config::configure()` for ordered loading.
- **`Cloude\Input::server($key?, $default?)`** (v2.14.1) — escape
  hatch for raw `$_SERVER` keys not covered by the typed helpers
  (`method`, `uri`, `header`, `ip`).
- **`Cloude\Str::sub($text, $start, $len?)` / `Str::len($text)`**
  (v2.14.2) — multibyte-safe wrappers over `mb_substr` / `mb_strlen`.
  `Str::truncate()` was also fixed in v2.12.1 to **never exceed the
  max length** (ellipsis budget comes OUT of `$length`, not on top).
