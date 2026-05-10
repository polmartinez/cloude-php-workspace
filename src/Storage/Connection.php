<?php

declare(strict_types=1);

namespace Cloude\Storage;

use Cloude\Config;

/**
 * Lazy, named PDO connection pool driven by `Cloude\Config`.
 *
 * Most apps have one DB connection. Some have several (read replica,
 * analytics warehouse, multi-tenant pools). This class lets every model
 * pick the connection it wants by name, without the app having to
 * thread `\PDO` instances through constructors.
 *
 * Configuration lives in `app/config/storage.php` (with per-environment
 * overrides under `app/config/<env>/storage.php`). Same file is used by
 * `Cloude\Storage\Factory` to build the right adapter for each driver:
 *
 *   return [
 *       'default' => [
 *           'driver' => 'pdo',
 *           'dsn'    => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
 *           'user'   => 'app',
 *           'pass'   => '',
 *       ],
 *       'analytics' => [
 *           'driver' => 'pdo',
 *           'dsn'    => 'mysql:host=warehouse;dbname=events',
 *           'user'   => 'app_ro',
 *           'pass'   => '',
 *       ],
 *       'content' => [
 *           'driver' => 'json',
 *           'path'   => __DIR__ . '/../../data',
 *       ],
 *   ];
 *
 * Usage:
 *
 *   \Cloude\Config::configure(__DIR__ . '/../config');     // once at boot
 *   $pdo = \Cloude\Storage\Connection::pdo('default');     // lazy, cached
 *
 *   // ...or just let Model auto-resolve from `static $connection`:
 *   class User extends Model {
 *       protected static string $table      = 'users';
 *       protected static string $connection = 'default';
 *   }
 *   User::find(1);   // no configure() needed
 *
 * The pool is static (one per process). `set()` and `reset()` exist so
 * tests can inject a `:memory:` SQLite or wipe state between cases.
 *
 * Connection is intentionally PDO-only. File-based storages
 * (`JsonStorage`, etc.) read their `path` directly from the same
 * config — there's no shared pool to manage there.
 *
 * Backwards-compat for v0.19's `db.php` filename: call
 * `Connection::setConfigName('db')` at boot and the loader uses
 * `db.<name>` instead of `storage.<name>`.
 */
final class Connection
{
    /** @var array<string, \PDO> */
    private static array $pool = [];

    private static string $configName = 'storage';

    /**
     * Override the config file name read by `pdo()` and the storage
     * `Factory`. Default is 'storage' (matches the namespace name).
     * Pass 'db' to keep the v0.19 convention.
     */
    public static function setConfigName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid config name: '$name'");
        }
        self::$configName = $name;
        self::$pool = [];
    }

    public static function configName(): string
    {
        return self::$configName;
    }

    public static function pdo(string $name = 'default'): \PDO
    {
        if (isset(self::$pool[$name])) {
            return self::$pool[$name];
        }
        $config = Config::get(self::$configName . '.' . $name);
        if (!is_array($config) || $config === []) {
            throw new \RuntimeException(
                "No connection named '$name' in " . self::$configName . ' config',
            );
        }
        $driver = $config['driver'] ?? 'pdo';
        if ($driver !== 'pdo') {
            throw new \RuntimeException(
                "Connection '$name' has driver '$driver' — Connection::pdo() only "
                . "handles 'pdo'. Use Cloude\\Storage\\Factory::make() instead.",
            );
        }
        return self::$pool[$name] = self::makePdo($config, $name);
    }

    /**
     * Inject an existing PDO under a name — typically used in tests:
     *
     *   Connection::set('default', new \PDO('sqlite::memory:'));
     */
    public static function set(string $name, \PDO $pdo): void
    {
        self::$pool[$name] = $pdo;
    }

    /**
     * Drop one cached connection, or all of them. Useful between tests
     * and in long-running CLI scripts where the DB cycles.
     */
    public static function reset(?string $name = null): void
    {
        if ($name === null) {
            self::$pool = [];
            return;
        }
        unset(self::$pool[$name]);
    }

    /**
     * @return array<int|string, \PDO>
     */
    public static function pool(): array
    {
        return self::$pool;
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function makePdo(array $config, string $name): \PDO
    {
        if (empty($config['dsn'])) {
            throw new \RuntimeException("Connection '$name' is missing required 'dsn' key");
        }

        $options = $config['options'] ?? [];
        if (!isset($options[\PDO::ATTR_ERRMODE])) {
            $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
        }
        if (!isset($options[\PDO::ATTR_DEFAULT_FETCH_MODE])) {
            $options[\PDO::ATTR_DEFAULT_FETCH_MODE] = \PDO::FETCH_ASSOC;
        }

        return new \PDO(
            (string) $config['dsn'],
            $config['user'] ?? null,
            $config['pass'] ?? null,
            $options,
        );
    }
}
