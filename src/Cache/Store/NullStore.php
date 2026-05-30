<?php

declare(strict_types=1);

namespace Cloude\Cache\Store;

use Cloude\Cache\Store;

/**
 * No-op store used when caching is disabled (`cache.default = false`).
 *
 * Every read misses, every write is silently dropped. Lets call sites
 * use `Cache::get()` / `Cache::remember()` / `Query::cache()`
 * unconditionally without needing a "is caching enabled?" branch
 * everywhere — the framework just makes the producer run every time.
 */
final class NullStore implements Store
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        // discard
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): void
    {
        // no-op
    }

    public function flush(): void
    {
        // no-op
    }
}
