<?php

declare(strict_types=1);

namespace Cloude\Markdown;

/**
 * Disk I/O helper for Markdown content with transparent gzip support.
 *
 * Files can be stored either as plain `.md` or as `.md.gz` (gzipped) to cut
 * disk and SFTP size by ~70%. Every read goes through this class, which
 * prefers `.gz` and falls back to the plain file (so legacy or hand-written
 * files still work).
 *
 * Writes go to `.gz`. If a plain `.md` exists for the same slug it is removed
 * after a successful gzip write — keeping the directory clean.
 *
 * Convention: callers always pass the *plain* path (e.g. `/.../article.md`).
 * This class appends `.gz` internally. Never pass `.gz` paths in.
 */
class File
{
    /**
     * Returns the actual on-disk path that backs $path (either $path.gz or
     * $path itself), or null if neither exists.
     */
    public static function resolve(string $path): ?string
    {
        if (file_exists($path . '.gz')) {
            return $path . '.gz';
        }
        if (file_exists($path)) {
            return $path;
        }
        return null;
    }

    /**
     * True if the markdown exists on disk (compressed or plain).
     */
    public static function exists(string $path): bool
    {
        return file_exists($path . '.gz') || file_exists($path);
    }

    /**
     * Reads the content, decompressing if necessary. Returns '' if missing or
     * decompression fails — callers already treat empty as "no content".
     */
    public static function read(string $path): string
    {
        if (file_exists($path . '.gz')) {
            $raw = @file_get_contents($path . '.gz');
            if ($raw === false) {
                return '';
            }
            $decoded = @gzdecode($raw);
            return $decoded === false ? '' : $decoded;
        }
        if (file_exists($path)) {
            return @file_get_contents($path) ?: '';
        }
        return '';
    }

    /**
     * Reads up to N bytes — used to extract frontmatter without loading the
     * whole article. With gzip we have to decode the whole stream because it
     * isn't seekable; we then truncate to $maxBytes.
     */
    public static function readPrefix(string $path, int $maxBytes = 4096): string
    {
        if (file_exists($path . '.gz')) {
            $full = self::read($path);
            return $full === '' ? '' : substr($full, 0, $maxBytes);
        }
        if (file_exists($path)) {
            return @file_get_contents($path, false, null, 0, $maxBytes) ?: '';
        }
        return '';
    }

    /**
     * mtime of whichever variant exists, or 0 if neither.
     */
    public static function mtime(string $path): int
    {
        if (file_exists($path . '.gz')) {
            return (int) filemtime($path . '.gz');
        }
        if (file_exists($path)) {
            return (int) filemtime($path);
        }
        return 0;
    }

    /**
     * Writes content as gzipped `.md.gz` (level 9). If a plain `.md` exists at
     * the same path it is removed afterwards to avoid duplicate content.
     */
    public static function write(string $path, string $content): bool
    {
        $gz = gzencode($content, 9);
        if ($gz === false) {
            return false;
        }
        $ok = file_put_contents($path . '.gz', $gz) !== false;
        if ($ok && file_exists($path)) {
            @unlink($path);
        }
        return $ok;
    }
}
