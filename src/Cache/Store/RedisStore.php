<?php

declare(strict_types=1);

namespace Cloude\Cache\Store;

use Cloude\Cache\Store;

/**
 * Redis-backed cache via `ext-redis` (a.k.a. phpredis). Predis is **not**
 * supported — the C extension is faster and ships with most modern PHP
 * containers. Install with `pecl install redis` or your distro's
 * `php-redis` package.
 *
 * Values are PHP-serialized before write so any cacheable value (arrays,
 * Models, DateTime, ...) round-trips cleanly without depending on
 * Redis's `OBJECT ENCODING`. TTL maps directly to Redis `EX`; `0` means
 * no expiration (just like the rest of the framework).
 *
 * Connections are lazy — the first `get`/`set`/etc. opens the socket.
 * Pass an existing `\Redis` instance via `__construct` if you'd rather
 * manage the lifecycle yourself (handy in tests with `RedisMock`).
 *
 * `flush()` only wipes keys under this store's `prefix` — it does NOT
 * call `FLUSHDB`. Sharing the same Redis instance across apps is safe
 * as long as each picks a unique prefix.
 */
final class RedisStore implements Store
{
    private ?\Redis $redis = null;

    /**
     * @param array{host?:string, port?:int, timeout?:float, password?:string, database?:int, prefix?:string} $config
     */
    public function __construct(
        private array $config = [],
        ?\Redis $client = null,
    ) {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException(
                'RedisStore needs ext-redis (phpredis). Install with `pecl install redis`.',
            );
        }
        $this->redis = $client;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->client()->get($this->prefix() . $key);
        if ($raw === false) {
            return $default;
        }
        $value = @unserialize((string) $raw, ['allowed_classes' => true]);
        if ($value === false && $raw !== serialize(false)) {
            return $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $payload = serialize($value);
        $name = $this->prefix() . $key;
        if ($ttl > 0) {
            $this->client()->setex($name, $ttl, $payload);
        } else {
            $this->client()->set($name, $payload);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->client()->exists($this->prefix() . $key);
    }

    public function forget(string $key): void
    {
        $this->client()->del($this->prefix() . $key);
    }

    public function flush(): void
    {
        $client  = $this->client();
        $pattern = $this->prefix() . '*';
        $iterator = null;
        // SCAN avoids blocking the server vs KEYS *.
        do {
            $keys = $client->scan($iterator, $pattern, 500);
            if ($keys === false || $keys === []) {
                continue;
            }
            $client->del($keys);
        } while ($iterator > 0);
    }

    private function client(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        $r = new \Redis();
        $host    = (string) ($this->config['host']    ?? '127.0.0.1');
        $port    = (int)    ($this->config['port']    ?? 6379);
        $timeout = (float)  ($this->config['timeout'] ?? 2.0);

        if (!@$r->connect($host, $port, $timeout)) {
            throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
        }
        if (!empty($this->config['password'])) {
            $r->auth((string) $this->config['password']);
        }
        if (isset($this->config['database'])) {
            $r->select((int) $this->config['database']);
        }
        $r->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        return $this->redis = $r;
    }

    private function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? 'cloude:');
    }
}
