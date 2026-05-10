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
 * Configuration lives in `app/config/db.php` (with per-environment
 * overrides under `app/config/<env>/db.php`):
 *
 *   return [
 *       'default' => [
 *           'dsn'     => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
 *           'user'    => 'app',
 *           'pass'    => '',
 *           'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
 *       ],
 *       'analytics' => [
 *           'dsn'  => 'mysql:host=warehouse;dbname=events',
 *           'user' => 'app_ro',
 *           'pass' => '',
 *       ],
 *   ];
 *
 * Usage:
 *
 *   \Cloude\Config::configure(__DIR__ . '/../config');     // once at boot
 *   $pdo = \Cloude\Storage\Connection::pdo('default');     // lazy, cached
 *
 *   \App\User::configure(new PdoStorage($pdo, 'users'));
 *   \App\Event::configure(new PdoStorage(Connection::pdo('analytics'), 'events'));
 *
 * The pool is static (one per process). `set()` and `reset()` exist so
 * tests can inject a `:memory:` SQLite or wipe state between cases.
 *
 * Connection is intentionally PDO-only. File-based storages
 * (`JsonStorage`, etc.) take their config directly (a directory path
 * via `Config::get('paths.content')`) — there's no shared pool to
 * manage there.
 */
final class Connection
{
    /** @var array<string, \PDO> */
    private static array $pool = [];

    public static function pdo(string $name = 'default'): \PDO
    {
        if (isset(self::$pool[$name])) {
            return self::$pool[$name];
        }
        $config = Config::get("db.$name");
        if (!is_array($config) || $config === []) {
            throw new \RuntimeException(
                "No database connection named '$name' in config (expected db.$name)",
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
