<?php

declare(strict_types=1);

/**
 * Recipe: multi-environment config + auto-resolved storage per model.
 *
 * Three pieces clicking together:
 *
 *   Cloude\Config              base + per-env file loader (FuelPHP-style)
 *   Cloude\Storage\Connection  lazy PDO pool keyed by config name
 *   Cloude\Storage\Factory     dispatches the right adapter by `driver`
 *
 * The big win: Model subclasses declare WHICH connection they want
 * (via `static $connection`) and the framework auto-resolves the
 * concrete `Storage` adapter from the config on first use. Bootstrap
 * shrinks to a single `Config::configure()` call.
 *
 * Recommended project layout:
 *
 *   my-app/
 *   ├── app/
 *   │   └── config/
 *   │       ├── app.php           ← base
 *   │       ├── storage.php       ← base
 *   │       ├── dev/              ← merged when env = 'dev'
 *   │       │   ├── app.php
 *   │       │   └── storage.php
 *   │       └── prod/
 *   │           ├── app.php
 *   │           └── storage.php
 *   └── www/
 *       └── index.php             ← Cloude\Config::configure(...) here
 *
 * Two starting environments (`dev`, `prod`) are conventional, but the
 * loader is open: drop a `staging/`, `test/`, `local/`, `ci/` directory
 * and it works automatically. Environment names are free-form strings.
 */

use Cloude\Config;
use Cloude\Model\Model;
use Cloude\Storage\Connection;

// ── 1. Bootstrap (once, in www/index.php) ────────────────────────────────────

Config::configure(
    configPath:  __DIR__ . '/../app/config',
    // null = auto-detect from the APP_ENV / ENVIRONMENT env var,
    // defaulting to 'dev'. Pass a string ('prod', 'staging', ...) to force.
    environment: null,
);

// ── 2. Per-environment config files ──────────────────────────────────────────
//
// app/config/storage.php (base — committed):
//
//   return [
//       'default' => [
//           'driver' => 'pdo',
//           'dsn'    => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
//           'user'   => 'app',
//           'pass'   => '',
//       ],
//       'analytics' => [
//           'driver' => 'pdo',
//           'dsn'    => 'mysql:host=warehouse;dbname=events',
//           'user'   => 'app_ro',
//           'pass'   => '',
//       ],
//       'content' => [
//           'driver' => 'json',
//           'path'   => __DIR__ . '/../../data',     // → {path}/{table}/{pk}.json
//       ],
//   ];
//
// app/config/prod/storage.php (override — usually .gitignored or env-sourced):
//
//   return [
//       'default' => [
//           'dsn'  => 'mysql:host=prod-master;dbname=app;charset=utf8mb4',
//           'user' => 'app_prod',
//           'pass' => getenv('DB_PASS'),
//       ],
//   ];
//
// app/config/dev/storage.php (override):
//
//   return [
//       'default' => ['dsn' => 'sqlite:' . __DIR__ . '/../../../var/dev.sqlite'],
//   ];
//
// Per-env values are deep-merged on top of base via Cloude\Arr::merge.
// In dev, 'analytics' and 'content' fall through to base unchanged.

// ── 3. Read config values from anywhere ──────────────────────────────────────

$appName  = Config::get('app.name');                            // string
$ttl      = Config::get('app.cache.ttl', 3600);                 // default fallback
$dbConfig = Config::get('storage.default');                     // sub-tree as array
$every    = Config::load('storage');                            // whole merged file

// ── 4. Models declare which connection they use ──────────────────────────────
//
// No User::configure() calls. The first call to find/save/query resolves
// the right Storage adapter from storage.<connection> in the config:
//
//   default  → driver: pdo  →  PdoStorage(Connection::pdo('default'), 'users')
//   content  → driver: json →  JsonStorage('/var/data/articles')
//   fake     → driver: array →  ArrayStorage([...])

class User extends Model
{
    protected static string $table      = 'users';
    protected static string $connection = 'default';            // PDO
}

class Event extends Model
{
    protected static string $table      = 'events';
    protected static string $connection = 'analytics';          // different PDO
}

class Article extends Model
{
    protected static string $table      = 'articles';
    protected static string $connection = 'content';            // JSON files
}

// Use without any explicit wiring:
User::find(1);
Article::find('intro-post');

// ── 5. Drop down when you still want to ──────────────────────────────────────
//
// The lower-level helpers stay accessible for the cases that need them:

$pdo  = Connection::pdo('default');                             // raw PDO instance
$rows = User::storage()->pdo()->query('SELECT ... JOIN ...')->fetchAll();

// ── 6. Testing: swap config + override the pool ──────────────────────────────
//
// In a PHPUnit setUp():
//
//   use Cloude\Model\Storage\ArrayStorage;
//
//   Config::setConfigPath(__DIR__ . '/fixtures/config');
//   Config::setEnvironment('test');
//   Connection::reset();
//   User::configure(new ArrayStorage([
//       ['id' => 1, 'name' => 'Ada'],
//   ]));
//
// Explicit configure() always wins over the config-driven auto-resolve.
