<?php

declare(strict_types=1);

/**
 * Recipe: caching values and SELECT queries.
 *
 * Two layers, picked separately:
 *
 *   Cloude\Cache\Cache         static façade with one default store
 *   Cloude\Storage\Query::cache() opt-in caching of SELECT terminals
 *
 * Stores ship for: array (in-process, tests), file (one .file per key
 * under a directory), redis (ext-redis), memcached (ext-memcached).
 * The default store is named by `cache.default`; everything else is
 * reachable via `Cache::store('name')`.
 *
 * **Caching is opt-in.** The framework ships with `default => false`,
 * so every `Cache::*` call no-ops until the app picks a driver. Set
 * `'default' => 'redis'` (or `'file'`, etc.) in `app/config/cache.php`
 * to turn it on. Example app config to enable Redis:
 *
 *   // app/config/cache.php
 *   return [
 *       'default' => 'redis',
 *       'redis'   => ['host' => 'cache.example.com', 'password' => '...'],
 *   ];
 *
 * Recommended project layout:
 *
 *   my-app/
 *   └── app/
 *       └── config/
 *           └── cache.php             ← overrides what the FW ships
 *
 * The framework's `config/cache.php` deep-merges underneath, so app
 * config only declares what differs (typically just `default` plus
 * host / port / prefix for the chosen driver).
 */

use Cloude\Cache\Cache;

// ── 1. Plain key/value caching ───────────────────────────────────────────────

Cache::put('homepage-html', '<html>…</html>', ttl: 600);   // 10 min
$html = Cache::get('homepage-html');

if (Cache::has('expensive-report')) {
    $report = Cache::get('expensive-report');
}

Cache::forget('homepage-html');
Cache::flush();                                            // wipe everything

// ── 2. Get-or-compute (the most useful pattern) ──────────────────────────────

$articles = Cache::remember('top-articles', ttl: 300, producer: function () {
    return Article::query()->orderBy('hits', 'DESC')->limit(10)->get();
});
// Producer runs only on cache miss. Result is stored under 'top-articles'
// for 5 minutes; subsequent calls in those 5 min return the cached array
// without re-hitting the DB.

// `null` IS cacheable — Cache::remember uses a sentinel internally, so
// "lookup returned null, please don't recompute" works correctly.

// ── 3. Caching SELECT queries directly ───────────────────────────────────────

// Add `->cache($ttl)` anywhere in the builder chain and EVERY SELECT
// terminal (get / first / count / value / pluck) is cached.
//
// The cache key defaults to sha1(sql + bindings), so changing the WHERE,
// columns or ORDER BY auto-invalidates by producing a different key.

$activeUsers = User::query()
    ->where('active', 1)
    ->cache(300)                       // 5-minute TTL
    ->get();

$totalActive = User::query()
    ->where('active', 1)
    ->cache(300)                       // count() is cached separately from get()
    ->count();

$emails = User::query()
    ->where('role', 'admin')
    ->cache(60)
    ->pluck('email');

// Mutations (insert / update / delete) ignore the cache flag — caching
// only wraps the SELECTs.

// ── 4. Explicit keys for manual invalidation ─────────────────────────────────

// When you need to invalidate the cached result after a write, pass a
// stable key so you can `Cache::forget()` it from the model:

User::query()->where('active', 1)->cache(300, key: 'users:active')->get();

class User extends \Cloude\Model\Model
{
    protected static string $table = 'users';

    protected function afterSave(): void
    {
        Cache::forget('users:active');
    }
}

// ── 5. Routing to a non-default store ────────────────────────────────────────

// Configure two stores and pick which one a query uses:
//
//   // app/config/cache.php
//   return [
//       'default' => 'redis',
//       'redis'   => ['driver' => 'redis', 'prefix' => 'app:'],
//       'fast'    => ['driver' => 'array'],         // hot path, in-process
//   ];

User::query()->cache(60, store: 'fast')->get();    // never leaves the process
User::query()->cache(60)->get();                   // → redis

// Same idea with the façade:
Cache::store('fast')->set('flag', true, ttl: 30);
$flag = Cache::store('fast')->get('flag');

// ── 6. Tests: swap in an ArrayStore ──────────────────────────────────────────

use Cloude\Cache\Store\ArrayStore;

Cache::reset();
Cache::set('default', new ArrayStore());

// Now every Cache::* call (and every cached Query) goes through the
// in-process store — no daemons, no network, predictable invalidation.
