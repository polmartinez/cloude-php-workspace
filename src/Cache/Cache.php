<?php

declare(strict_types=1);

namespace Cloude\Cache;

use Cloude\Cache\Store\ArrayStore;
use Cloude\Cache\Store\FileStore;
use Cloude\Cache\Store\MemcachedStore;
use Cloude\Cache\Store\NullStore;
use Cloude\Cache\Store\RedisStore;
use Cloude\Config;

/**
 * Static façade over the configured cache store(s). Reads
 * `config/cache.php` lazily on first use. Shape:
 *
 *   return [
 *       'default' => 'redis',                          // or false → caching off
 *       'array'     => ['driver' => 'array'],
 *       'file'      => ['driver' => 'file', 'path' => BASEPATH . '/data/cache'],
 *       'redis'     => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'cloude:'],
 *       'memcached' => ['driver' => 'memcached', 'servers' => [['127.0.0.1', 11211]]],
 *   ];
 *
 * Each top-level key (other than `default`) is a named store. The one
 * named by `default` is what `Cache::get/put/...` proxies to;
 * `Cache::store('name')` reaches any of the others. Stores are
 * memoized per name for the lifetime of the request.
 *
 * **Caching is opt-in.** The framework ships with `default => false`,
 * which means every `Cache::*` call is a no-op (`NullStore`) until
 * the app overrides `default` to a driver name in
 * `app/config/cache.php`. That way projects that don't need caching
 * pay zero cost and don't need a Redis/Memcached daemon to boot.
 *
 * Common patterns:
 *
 *   // Read / write / forget on the default store.
 *   Cache::set('homepage', $html, ttl: 600);
 *   $html = Cache::get('homepage');
 *   Cache::forget('homepage');
 *
 *   // The single most useful method — compute-or-return.
 *   $rows = Cache::remember('top-articles', 300, fn () =>
 *       Article::query()->orderBy('hits', 'DESC')->limit(10)->get()
 *   );
 *
 *   // Reach a non-default store explicitly.
 *   Cache::store('memcached')->set('foo', 1, 60);
 *
 * Tests inject an in-memory `ArrayStore`:
 *
 *   Cache::set('default', new ArrayStore());     // replaces the default
 *   Cache::reset();                              // wipes the registry
 */
final class Cache
{
    /** @var array<string, Store> */
    private static array $stores = [];

    private static string|false|null $defaultName = null;

    /**
     * Return the store registered under `$name`. With no argument
     * (or `'default'`), returns the configured default — or a
     * `NullStore` when `cache.default` is `false`. Lazily instantiated.
     */
    public static function store(?string $name = null): Store
    {
        if ($name === null || $name === 'default') {
            $default = self::defaultName();
            if ($default === false) {
                return self::$stores['__null__'] ??= new NullStore();
            }
            $name = $default;
        }
        if (isset(self::$stores[$name])) {
            return self::$stores[$name];
        }
        return self::$stores[$name] = self::makeStore($name);
    }

    /**
     * Inject a store under `$name` — typically used by tests. The
     * literal `'default'` resolves to whatever store the config
     * declares as default, so the test pattern
     * `Cache::set('default', new ArrayStore())` works regardless of
     * the configured driver name.
     */
    public static function set(string $name, Store $store): void
    {
        if ($name === 'default') {
            $resolved = self::defaultName();
            // When the config has caching off (default = false) but a
            // test wants to inject a real store as "the default", route
            // it to a stable internal slot the store() lookup honors.
            $name = $resolved === false ? '__default__' : $resolved;
            if ($resolved === false) {
                self::$defaultName = '__default__';
            }
        }
        self::$stores[$name] = $store;
    }

    /**
     * Clear every memoized store. Use between tests; on the next
     * `Cache::*` call the stores are rebuilt from config.
     */
    public static function reset(): void
    {
        self::$stores = [];
        self::$defaultName = null;
    }

    // ── default-store proxies ─────────────────────────────────────────────

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()->get($key, $default);
    }

    public static function put(string $key, mixed $value, int $ttl = 0): void
    {
        self::store()->set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }

    public static function forget(string $key): void
    {
        self::store()->forget($key);
    }

    public static function flush(): void
    {
        self::store()->flush();
    }

    /**
     * Get-or-compute. Returns the cached value when present; otherwise
     * invokes `$producer`, stores the result for `$ttl` seconds, and
     * returns it. The producer is called at most once per cache miss.
     *
     * `null` IS cacheable — we use a sentinel internally so the
     * happy-path is one round trip.
     */
    public static function remember(string $key, int $ttl, callable $producer): mixed
    {
        $sentinel = self::class . '@miss';
        $store = self::store();
        $hit = $store->get($key, $sentinel);
        if ($hit !== $sentinel) {
            return $hit;
        }
        $value = $producer();
        $store->set($key, $value, $ttl);
        return $value;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function defaultName(): string|false
    {
        if (self::$defaultName !== null) {
            return self::$defaultName;
        }
        $cfg = Config::get('cache.default');
        if ($cfg === false || $cfg === null || $cfg === '') {
            return self::$defaultName = false;
        }
        return self::$defaultName = (string) $cfg;
    }

    private static function makeStore(string $name): Store
    {
        $config = Config::get('cache.' . $name);
        if (!is_array($config) || $config === []) {
            throw new \RuntimeException(
                "No cache store named '$name' in cache config (cache.$name)",
            );
        }
        $driver = (string) ($config['driver'] ?? '');
        return match ($driver) {
            'array'     => new ArrayStore(),
            'file'      => new FileStore((string) ($config['path']
                ?? throw new \RuntimeException("File cache store '$name' is missing 'path'"))),
            'redis'     => new RedisStore($config),
            'memcached' => new MemcachedStore($config),
            default     => throw new \RuntimeException(
                "Unknown cache driver '$driver' for store '$name' "
                . '(supported: array, file, redis, memcached)',
            ),
        };
    }
}
