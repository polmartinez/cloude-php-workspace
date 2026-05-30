<?php

declare(strict_types=1);

namespace Cloude\Cache\Store;

use Cloude\Cache\Store;

/**
 * Memcached-backed cache via `ext-memcached`. Install with `pecl
 * install memcached` or your distro's `php-memcached` package.
 *
 * The driver delegates serialization to `Memcached` itself (igbinary
 * when present, PHP serialize otherwise) — that's a deliberate fast
 * path; we don't double-encode. TTL maps to the `$expiration` arg in
 * `Memcached::set()`; `0` means no expiration.
 *
 * `flush()` only deletes keys with this store's `prefix` — it does NOT
 * call `flush()` on the server (which would nuke everyone's data on a
 * shared instance). Memcached doesn't have key enumeration, so we
 * piggy-back on the persistent-id feature: every `set()` records the
 * key in a sidecar index (also stored in memcached, under the prefix);
 * `flush()` reads the index and deletes those keys.
 *
 * For high-throughput workloads where the index write overhead matters,
 * switch the consumer to Redis — its `SCAN` is the right primitive for
 * this job.
 */
final class MemcachedStore implements Store
{
    private ?\Memcached $client = null;

    /**
     * @param array{servers?: list<array{0: string, 1?: int, 2?: int}>, prefix?: string, persistent_id?: string} $config
     */
    public function __construct(
        private array $config = [],
        ?\Memcached $client = null,
    ) {
        if (!class_exists(\Memcached::class)) {
            throw new \RuntimeException(
                'MemcachedStore needs ext-memcached. Install with `pecl install memcached`.',
            );
        }
        $this->client = $client;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->client()->get($this->prefix() . $key);
        if ($value === false && $this->client()->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $name = $this->prefix() . $key;
        // Memcached treats TTLs > 30 days as absolute timestamps — keep
        // it as a relative offset by capping at 30 days. Callers asking
        // for longer should just refresh.
        if ($ttl > 60 * 60 * 24 * 30) {
            $ttl = 60 * 60 * 24 * 30;
        }
        $this->client()->set($name, $value, max(0, $ttl));
        $this->indexAdd($key);
    }

    public function has(string $key): bool
    {
        $this->client()->get($this->prefix() . $key);
        return $this->client()->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function forget(string $key): void
    {
        $this->client()->delete($this->prefix() . $key);
        $this->indexRemove($key);
    }

    public function flush(): void
    {
        $index = $this->client()->get($this->indexKey()) ?: [];
        if (!is_array($index) || $index === []) {
            return;
        }
        $prefixed = array_map(fn (string $k): string => $this->prefix() . $k, array_keys($index));
        $this->client()->deleteMulti($prefixed);
        $this->client()->delete($this->indexKey());
    }

    private function client(): \Memcached
    {
        if ($this->client instanceof \Memcached) {
            return $this->client;
        }
        $id = $this->config['persistent_id'] ?? null;
        $m  = $id !== null ? new \Memcached((string) $id) : new \Memcached();
        // Only seed servers once when using a persistent_id, otherwise
        // we end up with duplicate connections.
        if ($m->getServerList() === []) {
            $servers = $this->config['servers'] ?? [['127.0.0.1', 11211]];
            $normalised = [];
            foreach ($servers as $s) {
                $normalised[] = [
                    (string) ($s[0] ?? '127.0.0.1'),
                    (int) ($s[1] ?? 11211),
                    (int) ($s[2] ?? 0),
                ];
            }
            $m->addServers($normalised);
        }
        return $this->client = $m;
    }

    private function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? 'cloude:');
    }

    private function indexKey(): string
    {
        return $this->prefix() . '__index__';
    }

    /** Append `$key` to the sidecar index used by `flush()`. */
    private function indexAdd(string $key): void
    {
        $idx = $this->client()->get($this->indexKey());
        if (!is_array($idx)) {
            $idx = [];
        }
        $idx[$key] = 1;
        $this->client()->set($this->indexKey(), $idx, 0);
    }

    private function indexRemove(string $key): void
    {
        $idx = $this->client()->get($this->indexKey());
        if (!is_array($idx) || !isset($idx[$key])) {
            return;
        }
        unset($idx[$key]);
        $this->client()->set($this->indexKey(), $idx, 0);
    }
}
