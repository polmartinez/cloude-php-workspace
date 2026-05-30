<?php

declare(strict_types=1);

namespace Cloude\Cache;

/**
 * Contract every cache driver implements. Small on purpose — five
 * methods. Anything richer (tags, atomic counters, locks) is out of
 * scope; if you need it, drop down to the driver-specific client.
 *
 * `ttl` is in seconds. Zero means "no expiration" (the entry lives
 * until evicted by memory pressure or explicit `forget()` / `flush()`).
 * Negative values are treated as zero by every shipped driver.
 *
 * Stores serialize/unserialize internally — callers pass and receive
 * native PHP values. Resources and closures are not cacheable.
 */
interface Store
{
    /** Returns the cached value, or `$default` when missing/expired. */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Stores `$value` under `$key` for `$ttl` seconds. `$ttl = 0` means
     * no expiration. Existing values under the same key are overwritten.
     */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    /** Returns true if a non-expired entry exists. */
    public function has(string $key): bool;

    /** Removes a single key. No-op when the key is missing. */
    public function forget(string $key): void;

    /** Wipes everything in this store's namespace. */
    public function flush(): void;
}
