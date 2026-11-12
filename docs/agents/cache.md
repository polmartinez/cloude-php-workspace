# Cache

> Part of the [AGENTS](../../AGENTS.md) reference. `Cloude\Cache\Cache`
> (values and SELECT results) — configuration, the calls to use, what
> each driver guarantees, how failures surface and how to test.
>
> Not `Cloude\Http\Cache`: that class only writes HTTP cache headers.

## Mental model

- One static façade, `Cloude\Cache\Cache`, in front of named **stores**.
- A store is one driver + its settings. Four drivers ship: `array`,
  `file`, `redis`, `memcached`. A fifth, `NullStore`, is what you get
  when caching is off.
- **Off by default.** The framework's `config/cache.php` has
  `'default' => false`: every `Cache::*` call is a no-op and every
  `Query::cache()` runs the SELECT live. Code written against the
  façade therefore works unchanged with caching off — never add an
  "is the cache enabled?" branch.

## Configuration

`app/config/cache.php` — only the keys that differ from the framework
defaults (deep-merged, per environment under `config/<env>/cache.php`):

```php
return [
    'default' => 'redis',            // false | 'array' | 'file' | 'redis' | 'memcached' | any key below
    'redis'   => ['host' => 'cache.internal', 'password' => \Cloude\Config::env('REDIS_PASSWORD')],
];
```

Each top-level key other than `default` is a store:

| Store key (default name) | Settings | Needs |
|---|---|---|
| `array` | `driver: array` | nothing — lives for one request |
| `file` | `driver: file`, `path` (required, writable dir) | filesystem shared by the PHP processes |
| `redis` | `driver: redis`, `host`, `port`, `password`, `database`, `timeout`, `prefix` | `ext-redis` (phpredis; Predis is not supported) |
| `memcached` | `driver: memcached`, `servers` (`[[host, port], …]`), `prefix` | `ext-memcached` |

A store can have any name (`'fast' => ['driver' => 'array']`); the
`driver` key picks the class.

The shipped defaults read these environment variables: `REDIS_HOST`,
`REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`, `MEMCACHED_HOST`,
`MEMCACHED_PORT`, `CACHE_PREFIX`. Which store is the default is **not**
taken from the environment — set `default` in config (wrap it in
`Config::env('CACHE_DRIVER', false)` yourself if you want that).

## Calls

```php
use Cloude\Cache\Cache;

Cache::put('key', $value, 600);          // ttl in seconds; 0 (default) = no expiry
Cache::get('key', $default = null);
Cache::has('key');
Cache::forget('key');
Cache::flush();                          // everything under this store's prefix

$rows = Cache::remember('top-articles', 300, fn () => Article::query()->limit(10)->get());

Cache::store('fast')->set('k', $v, 30);  // a named store: get / set / has / forget / flush
```

- The façade's write is **`put()`**. `Cache::set($name, $store)` is a
  different thing: it registers a store object (tests).
- `remember()` calls the producer only on a miss. `null` and `false`
  are values, not misses.
- Values are PHP values (scalars, arrays, objects that serialize).
  No closures, no resources.
- No tags, no locks, no atomic counters. Under a miss stampede several
  requests may run the producer at once.

## Caching queries

```php
User::query()->where('active', 1)->cache(300)->get();                       // automatic key
User::query()->where('active', 1)->cache(300, key: 'users:active')->get();   // key you can forget
User::query()->cache(60, store: 'fast')->count();                            // another store
```

- Applies to `get`, `first`, `count`, `value`, `pluck`. Writes ignore it.
- Automatic key: `cloude.q.` + `sha1(connection + sql + bindings)`.
  Any change to the query gives another entry; nothing invalidates the
  old one but its TTL.
- To invalidate on write, give the query a `key:` and call
  `Cache::forget('users:active')` in the model's `afterSave()`.
  `afterSave()` runs for `save()`, `create()`, `updateOrCreate()` and
  `upsert()` — not for `Query::update()` / `delete()` or `$model->delete()`.
- Cached rows are plain arrays as the driver returned them at the time.

## What each driver guarantees

| | `array` | `file` | `redis` | `memcached` |
|---|---|---|---|---|
| Shared between requests | no | yes, one host | yes | yes |
| TTL enforced by | PHP, on read | PHP, on read (expired file deleted) | the server | the server (capped at 30 days) |
| `flush()` removes | everything in the object | every file under `path` | keys matching `prefix*` via `SCAN` — never `FLUSHDB` | keys this store recorded in its index key — never the server-wide flush |
| Safe to share a server with other apps | — | — | yes, with a distinct `prefix` | yes, with a distinct `prefix` |
| Returns objects by reference | yes (same instance back) | no (copy) | no (copy) | no (copy) |
| Exercised in the test suite | unit | unit | unit + a real Redis 7 in CI | constructor / wiring only — **not run against a real server** |

## Failures

| Situation | What happens |
|---|---|
| `default` is `false` | No-op. Reads miss, writes are dropped, `remember()` runs the producer every time |
| Store name not in config | `RuntimeException("No cache store named '…'")` on first use |
| `file` store without `path`, or directory not writable | `RuntimeException` |
| `ext-redis` / `ext-memcached` not loaded | `RuntimeException` naming the extension, when the store is first built |
| Redis unreachable, password rejected, bad `database` | `RuntimeException("Failed to connect to Redis at host:port…")` on the first call that needs the connection (connections are lazy). The phpredis exception is its `getPrevious()` |
| Redis drops mid-request | the phpredis `\RedisException` propagates |

The cache never degrades silently: a configured store that cannot be
reached throws. If a page must survive a cache outage, catch around
the call and compute the value directly:

```php
try {
    $rows = Cache::remember('top', 300, $load);
} catch (\RuntimeException | \RedisException) {
    $rows = $load();
}
```

## Sessions on Redis / Memcached

Separate from the cache stores: `config/session.php` with
`'handler' => 'redis'` (or `'memcached'`) and its own block. PHP's
session extension talks to the server; `Cloude\Session` only builds
the connection string. See the Sessions rows in the
[decision matrix](decision-matrix.md).

## Testing code that uses the cache

```php
use Cloude\Cache\Cache;
use Cloude\Cache\Store\ArrayStore;

protected function setUp(): void
{
    Cache::reset();
    Cache::set('default', new ArrayStore());   // works whatever the config says, even `false`
}

protected function tearDown(): void
{
    Cache::reset();
}
```

- Prove a hit by changing the source after the first call and asserting
  the old value still comes back (see `tests/Storage/QueryCacheTest.php`).
- Don't sleep to test expiry; assert the TTL that was set, or use a
  store whose state you can edit.
- Real-server tests: `tests/Cache/RedisIntegrationTest.php`, enabled by
  `CLOUDE_TEST_REDIS_HOST` + `ext-redis`. How to run it, and the rule
  that CI fails on a skipped test: [`testing.md`](testing.md).
