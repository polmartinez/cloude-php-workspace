<?php

declare(strict_types=1);

namespace Cloude\Storage;

use Cloude\Config;
use Cloude\Model\Storage;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\JsonCollectionStorage;
use Cloude\Model\Storage\JsonStorage;
use Cloude\Model\Storage\MarkdownStorage;
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
 *          `$path/$table/` as the storage directory — one .json
 *          per record. Recognises `primary_key` (default 'id') and
 *          `auto_increment` (default false, otherwise UUIDs).
 *
 *   json_collection → ONE file IS the whole collection (a dict keyed
 *          by PK). Reads `path` (parent dir) and uses
 *          `$path/$table.json` as the storage file. Recognises
 *          `primary_key` (default 'id'). Pick this when the data is
 *          already shaped as one document with many entries.
 *
 *   array → starts empty unless `data` (a list of seed rows) is
 *           supplied. Recognises `primary_key` (default 'id').
 *
 * Two entry points:
 *   - `make($connectionName, $table)` — looks the connection up in
 *     `storage.<name>` config, then dispatches.
 *   - `makeFromConfig($config, $table, $connectionName = null)` — same
 *     dispatch but with a pre-built config array (used when a Model
 *     declares `protected static array $connection = [...]` inline).
 *
 * Unknown drivers throw — there's no plugin registry. If you need
 * Redis / Mongo / something custom, subclass your Model and override
 * `storage()`, or wire the storage explicitly via
 * `Model::configure(new MyStorage(...))`.
 */
final class Factory
{
    /**
     * Resolve a named connection from Config (`storage.<name>`) and build
     * the right adapter. The high-traffic entry point — `Model::storage()`
     * calls this when the model has `protected static string $connection`.
     */
    public static function make(string $connectionName, string $table): Storage
    {
        $config = Config::get(Connection::configName() . '.' . $connectionName);
        if (!is_array($config) || $config === []) {
            throw new \RuntimeException(
                "Storage connection '$connectionName' not found in "
                . Connection::configName() . ' config',
            );
        }
        return self::makeFromConfig($config, $table, $connectionName);
    }

    /**
     * Build an adapter from a raw config array. Called by `make()` after
     * resolving a named connection, and directly by `Model::storage()`
     * when a model declares an inline `protected static array $connection`.
     *
     * `pdo` driver requires `$connectionName` (the pool in
     * `Cloude\Storage\Connection` keys instances by name). Inline arrays
     * therefore only support file-based drivers — `json`,
     * `json_collection`, `array`.
     *
     * @param array<string,mixed> $config
     */
    public static function makeFromConfig(array $config, string $table, ?string $connectionName = null): Storage
    {
        $driver = $config['driver'] ?? 'pdo';

        return match ($driver) {
            'pdo' => self::pdoStorage(
                $connectionName ?? throw new \InvalidArgumentException(
                    "'pdo' driver requires a named connection (the PDO pool "
                    . 'keys instances by name). Declare the connection in '
                    . 'storage.php and reference it by string instead of an inline array.',
                ),
                $table,
                $config,
            ),
            'json'            => self::jsonStorage($table, $config),
            'json_collection' => self::jsonCollectionStorage($table, $config),
            'markdown'        => self::markdownStorage($table, $config),
            'array'           => self::arrayStorage($config),
            default           => throw new \RuntimeException(
                "Unknown storage driver '$driver'"
                . ($connectionName !== null ? " for connection '$connectionName'" : ' (inline config)')
                . ' (supported: pdo, json, json_collection, markdown, array)',
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
    private static function markdownStorage(string $table, array $config): MarkdownStorage
    {
        if (empty($config['path'])) {
            throw new \RuntimeException(
                "'markdown' driver requires a 'path' key (parent directory; storage dir = path/table)",
            );
        }
        $base = rtrim((string) $config['path'], '/');
        $dir  = $table === '' ? $base : $base . '/' . $table;
        return new MarkdownStorage(
            $dir,
            (string) ($config['primary_key'] ?? 'slug'),
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function jsonCollectionStorage(string $table, array $config): JsonCollectionStorage
    {
        if (empty($config['path'])) {
            throw new \RuntimeException(
                "'json_collection' driver requires a 'path' key (parent directory; file = path/table.json)",
            );
        }
        return new JsonCollectionStorage(
            rtrim((string) $config['path'], '/') . '/' . $table . '.json',
            (string) ($config['primary_key'] ?? 'id'),
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
