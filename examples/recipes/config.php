<?php

declare(strict_types=1);

/**
 * Recipe: multi-environment config + named DB connections.
 *
 * Two pieces working together:
 *   - Cloude\Config        — base + per-env file loader (FuelPHP-style)
 *   - Cloude\Storage\Connection — lazy PDO pool, configured from Config
 *
 * Recommended project layout:
 *
 *   my-app/
 *   ├── app/
 *   │   └── config/
 *   │       ├── app.php       ← base
 *   │       ├── db.php        ← base
 *   │       ├── dev/          ← merged when env = 'dev'
 *   │       │   ├── app.php
 *   │       │   └── db.php
 *   │       └── prod/
 *   │           ├── app.php
 *   │           └── db.php
 *   └── www/
 *       └── index.php         ← Cloude\Config::configure(...) here
 *
 * Two starting environments (`dev`, `prod`) are conventional, but the
 * loader is open: drop a `staging/`, `test/`, `local/`, `ci/` directory
 * and it works automatically. Environment names are free-form strings.
 */

use Cloude\Config;
use Cloude\Model\Model;
use Cloude\Model\Storage\PdoStorage;
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
// app/config/db.php (base — committed):
//
//   return [
//       'default' => [
//           'dsn'  => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
//           'user' => 'app',
//           'pass' => '',
//       ],
//       'analytics' => [
//           'dsn'  => 'mysql:host=warehouse;dbname=events',
//           'user' => 'app_ro',
//           'pass' => '',
//       ],
//   ];
//
// app/config/prod/db.php (override — usually .gitignored or env-sourced):
//
//   return [
//       'default' => [
//           'dsn'  => 'mysql:host=prod-master;dbname=app;charset=utf8mb4',
//           'user' => 'app_prod',
//           'pass' => getenv('DB_PASS'),
//       ],
//   ];
//
// app/config/dev/db.php (override):
//
//   return [
//       'default' => ['dsn' => 'sqlite:' . __DIR__ . '/../../../var/dev.sqlite'],
//   ];
//
// Per-env values are deep-merged on top of base via Cloude\Arr::merge.
// In dev, 'analytics' falls through to base unchanged.

// ── 3. Read config values from anywhere ──────────────────────────────────────

$appName  = Config::get('app.name');                            // string
$ttl      = Config::get('app.cache.ttl', 3600);                 // default fallback
$dbConfig = Config::get('db.default');                          // sub-tree as array
$everyDb  = Config::load('db');                                 // whole merged file

// ── 4. Get a PDO connection by name ──────────────────────────────────────────

$pdo       = Connection::pdo('default');                        // lazy + cached
$analytics = Connection::pdo('analytics');                      // different instance

// ── 5. Wire models to whichever connection they belong to ────────────────────

class User extends Model
{
    protected static string $table = 'users';
}

class Event extends Model
{
    protected static string $table = 'events';
}

User::configure(new PdoStorage(Connection::pdo('default'), 'users'));
Event::configure(new PdoStorage(Connection::pdo('analytics'), 'events'));

// ── 6. Testing: swap config + override the pool ──────────────────────────────
//
// In a PHPUnit setUp():
//
//   Config::setConfigPath(__DIR__ . '/fixtures/config');
//   Config::setEnvironment('test');
//   Connection::reset();
//   Connection::set('default', new \PDO('sqlite::memory:'));
//
// The model code under test stays unchanged — it just asks Connection::pdo()
// and gets the in-memory SQLite back.
