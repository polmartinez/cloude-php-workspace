<?php

declare(strict_types=1);

namespace Cloude\Storage;

use Cloude\Config;
use Cloude\Model\Storage;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\JsonStorage;
use Cloude\Model\Storage\PdoStorage;

/**
 * Builds the right `Storage` adapter for a connection-name + table,
 * dispatching on the `driver` key in the storage config.
 *
 * Config shape (`app/config/storage.php`):
 *
 *   return [
 *       'default' => [
 *           'driver' => 'pdo',
 *           'dsn'    => 'mysql:host=localhost;dbname=app;charset=utf8mb4',
 *           'user'   => 'app',
 *           'pass'   => '',
 *       ],
 *       'content' => [
 *           'driver' => 'json',
 *           'path'   => __DIR__ . '/../../data',
 *       ],
 *       'fake' => [
 *           'driver' => 'array',          // for "stub" environments
 *       ],
 *   ];
 *
 * Driver-specific options the factory understands:
 *
 *   pdo  → uses Connection::pdo($name) under the hood (cached PDO).
 *          Recognises `primary_key` (default 'id') and `quote_char`
 *          (default '`'). All other PDO config keys (dsn, user, pass,
 *          options) are consumed by Connection.
 *
 *   json → reads `path` (required, the parent dir) and uses
 *          `$path/$table/` as the storage directory.
 *          Recognises `primary_key` (default 'id') and
 *          `auto_increment` (default false, otherwise UUIDs).
 *
 *   array → starts empty unless `data` (a list of seed rows) is
 *           supplied. Recognises `primary_key` (default 'id').
 *
 * Unknown drivers throw — there's no plugin registry in v0.20. If you
 * need Redis / Mongo / something custom, subclass your Model and
 * override `storage()`, or wire the storage explicitly via
 * `Model::configure(new MyStorage(...))`.
 */
final class Factory
{
    public static function make(string $connectionName, string $table): Storage
    {
        $config = Config::get(Connection::configName() . '.' . $connectionName);
        if (!is_array($config) || $config === []) {
            throw new \RuntimeException(
                "Storage connection '$connectionName' not found in "
                . Connection::configName() . ' config',
            );
        }

        $driver = $config['driver'] ?? 'pdo';

        return match ($driver) {
            'pdo'   => self::pdoStorage($connectionName, $table, $config),
            'json'  => self::jsonStorage($table, $config),
            'array' => self::arrayStorage($config),
            default => throw new \RuntimeException(
                "Unknown storage driver '$driver' for connection '$connectionName' "
                . '(supported: pdo, json, array)',
            ),
        };
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function pdoStorage(string $name, string $table, array $config): PdoStorage
    {
        return new PdoStorage(
            Connection::pdo($name),
            $table,
            (string) ($config['primary_key'] ?? 'id'),
            (string) ($config['quote_char'] ?? '`'),
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function jsonStorage(string $table, array $config): JsonStorage
    {
        if (empty($config['path'])) {
            throw new \RuntimeException("'json' driver requires a 'path' key (parent directory)");
        }
        return new JsonStorage(
            rtrim((string) $config['path'], '/') . '/' . $table,
            (string) ($config['primary_key'] ?? 'id'),
            (bool) ($config['auto_increment'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function arrayStorage(array $config): ArrayStorage
    {
        /** @var list<array<string,mixed>> $seed */
        $seed = $config['data'] ?? [];
        return new ArrayStorage($seed, (string) ($config['primary_key'] ?? 'id'));
    }
}
