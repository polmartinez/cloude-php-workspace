<?php

declare(strict_types=1);

/**
 * Framework-shipped defaults for `Cloude\Cache\Cache`.
 *
 * Auto-loaded by `Cloude\Config` whenever the app calls
 * `Config::configure(APPPATH . '/config')`. The app's own
 * `app/config/cache.php` is deep-merged onto this base, so projects
 * only declare what differs.
 *
 * Shape: one top-level key per available driver, plus `default` that
 * names which one to use. **`default` is `false` here** — caching is
 * opt-in: until the app sets `'default' => 'redis'` (or any other
 * driver name) every `Cache::*` call is a passthrough and every
 * `Query::cache()` runs the SELECT live. That keeps the framework
 * cost-free for projects that don't need caching at all.
 *
 * To enable: in `app/config/cache.php` override `default` to one of
 * `'array'`, `'file'`, `'redis'`, `'memcached'`, and (if needed)
 * override the matching driver block's connection details.
 */

return [
    // Set to a driver name in app/config/cache.php to enable caching.
    // false → every Cache::* call is a no-op (NullStore).
    'default' => false,

    // In-memory; lives for the current PHP request only. Useful in tests
    // and as a target for explicit Cache::store('array') calls.
    'array' => [
        'driver' => 'array',
    ],

    // One file per key under `path/<2-char-shard>/<sha1>`. App config
    // must set 'path' to a writable directory.
    'file' => [
        'driver' => 'file',
        'path'   => null,
    ],

    // ext-redis. App config typically only overrides 'host' / 'port' /
    // 'password' / 'database'. 'prefix' isolates this app from any
    // other tenants sharing the same Redis instance.
    'redis' => [
        'driver'   => 'redis',
        'host'     => \Cloude\Config::env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) \Cloude\Config::env('REDIS_PORT', '6379'),
        'password' => \Cloude\Config::env('REDIS_PASSWORD'),
        'database' => (int) \Cloude\Config::env('REDIS_DB', '0'),
        'timeout'  => 2.0,
        'prefix'   => \Cloude\Config::env('CACHE_PREFIX', 'cloude:'),
    ],

    // ext-memcached. 'servers' is a list of [host, port, weight?] tuples.
    'memcached' => [
        'driver'  => 'memcached',
        'servers' => [
            [\Cloude\Config::env('MEMCACHED_HOST', '127.0.0.1'), (int) \Cloude\Config::env('MEMCACHED_PORT', '11211')],
        ],
        'prefix'  => \Cloude\Config::env('CACHE_PREFIX', 'cloude:'),
    ],
];
