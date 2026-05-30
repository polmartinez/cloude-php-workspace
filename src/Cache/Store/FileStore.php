<?php

declare(strict_types=1);

namespace Cloude\Cache\Store;

use Cloude\Cache\Store;

/**
 * Filesystem cache — one file per key under a configurable directory.
 * Survives across requests on a single host. Cheap, no daemons needed.
 *
 * Layout: keys are hashed (sha1) and split into a two-char shard prefix
 * so a hot cache directory doesn't end up with 100k entries in a single
 * inode. Each file stores `expires_at\nserialize($value)`.
 *
 * Locking: writes go through a temp file + atomic rename so concurrent
 * readers never see a torn payload. No read locks needed.
 *
 * Use this when you can't / don't want to run Redis or Memcached but
 * still want to share cache state across requests on the same box.
 */
final class FileStore implements Store
{
    public function __construct(private string $path)
    {
        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new \RuntimeException("Cache directory is not writable: {$this->path}");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->pathFor($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $newline = strpos($raw, "\n");
        if ($newline === false) {
            @unlink($file);
            return $default;
        }
        $expires = (int) substr($raw, 0, $newline);
        if ($expires !== 0 && $expires < time()) {
            @unlink($file);
            return $default;
        }
        $payload = substr($raw, $newline + 1);
        $value = @unserialize($payload, ['allowed_classes' => true]);
        if ($value === false && $payload !== serialize(false)) {
            @unlink($file);
            return $default;
        }
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $file = $this->pathFor($key);
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cache shard directory is not writable: {$dir}");
        }
        $expires = $ttl > 0 ? time() + $ttl : 0;
        $payload = $expires . "\n" . serialize($value);

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write cache file: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to rename cache file into place: {$file}");
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function forget(string $key): void
    {
        @unlink($this->pathFor($key));
    }

    public function flush(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                @unlink($f->getPathname());
            } elseif ($f->isDir() && $f->getPathname() !== $this->path) {
                @rmdir($f->getPathname());
            }
        }
    }

    private function pathFor(string $key): string
    {
        $hash = sha1($key);
        return $this->path . '/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
