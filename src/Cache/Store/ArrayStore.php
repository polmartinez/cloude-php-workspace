<?php

declare(strict_types=1);

namespace Cloude\Cache\Store;

use Cloude\Cache\Store;

/**
 * In-process cache. Lives for the duration of the PHP request — nothing
 * is shared across processes. Useful in tests and for tight inner loops
 * where you want to dedupe within a single request without touching a
 * network store.
 *
 * Values are kept by reference (no serialize round-trip), so storing an
 * object and reading it back returns the same instance. Mutating it
 * mutates the cached copy.
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->entries[$key])) {
            return $default;
        }
        $entry = $this->entries[$key];
        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            unset($this->entries[$key]);
            return $default;
        }
        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->entries[$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
