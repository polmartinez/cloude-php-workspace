<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Tiny CLI helper for scripts under app/cli/. Two responsibilities:
 *
 *   1. parse $argv into a usable array (long flags, --key=value, positional).
 *   2. emit coloured info / warn / error / success output, with TTY detection
 *      so logs piped to a file stay clean.
 *
 * Typical usage:
 *
 *   $args = Cli::parseArgs($argv);
 *   $dryRun = Cli::flag($args, 'dry-run');
 *   $limit  = (int) (Cli::option($args, 'limit') ?? 100);
 *
 *   Cli::info("processing $limit items");
 *   if ($errors > 0) {
 *       Cli::error("$errors items failed");
 *       exit(1);
 *   }
 *   Cli::success('done');
 */
class Cli
{
    /**
     * Parses $argv into a flat associative array.
     *
     *   --foo            → ['foo' => true]
     *   --foo=bar        → ['foo' => 'bar']
     *   -x               → ['x' => true]
     *   positional       → goes into $args['_'] as a list
     *
     * The script name ($argv[0]) is skipped. The standalone token "--"
     * stops flag parsing; everything after it is positional.
     *
     * @param array<int,string> $argv
     * @return array<string,mixed>
     */
    public static function parseArgs(array $argv): array
    {
        $args = ['_' => []];
        $positionalOnly = false;
        $n = count($argv);
        for ($i = 1; $i < $n; $i++) {
            $arg = $argv[$i];
            if ($positionalOnly) {
                $args['_'][] = $arg;
                continue;
            }
            if ($arg === '--') {
                $positionalOnly = true;
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $rest = substr($arg, 2);
                if (str_contains($rest, '=')) {
                    [$k, $v] = explode('=', $rest, 2);
                    $args[$k] = $v;
                } else {
                    $args[$rest] = true;
                }
                continue;
            }
            if (str_starts_with($arg, '-') && strlen($arg) >= 2) {
                $args[substr($arg, 1)] = true;
                continue;
            }
            $args['_'][] = $arg;
        }
        return $args;
    }

    /**
     * Returns the flag value as a boolean. Truthy strings: "1", "true",
     * "yes", "on" (case-insensitive). Anything else, missing, or false → false.
     *
     * @param array<string,mixed> $args
     */
    public static function flag(array $args, string $name, bool $default = false): bool
    {
        if (!array_key_exists($name, $args)) {
            return $default;
        }
        $v = $args[$name];
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Returns a string option value (e.g. "--limit=100"). Returns $default
     * if missing or if the arg was passed as a bare flag without a value.
     *
     * @param array<string,mixed> $args
     */
    public static function option(array $args, string $name, ?string $default = null): ?string
    {
        $v = $args[$name] ?? null;
        if ($v === null || is_bool($v)) {
            return $default;
        }
        return (string) $v;
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function positional(array $args, int $index, ?string $default = null): ?string
    {
        return $args['_'][$index] ?? $default;
    }

    /**
     * True when STDOUT is a terminal — used to gate ANSI colour output so
     * that piping to a file or another process produces clean text.
     */
    public static function isTty(): bool
    {
        if (!defined('STDOUT')) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        return false;
    }

    public static function out(string $msg): void
    {
        self::write(STDOUT, $msg);
    }

    public static function info(string $msg): void
    {
        self::write(STDOUT, self::color('[info] ' . $msg, '36'));
    }

    public static function warn(string $msg): void
    {
        self::write(STDERR, self::color('[warn] ' . $msg, '33'));
    }

    public static function error(string $msg): void
    {
        self::write(STDERR, self::color('[error] ' . $msg, '31'));
    }

    public static function success(string $msg): void
    {
        self::write(STDOUT, self::color('[ok] ' . $msg, '32'));
    }

    /**
     * Aborts the script with $code as exit status. Calls `exit()`, so it
     * never returns. Use `Cli::error` first if you want a message.
     */
    public static function abort(int $code = 1, ?string $message = null): never
    {
        if ($message !== null) {
            self::error($message);
        }
        exit($code);
    }

    private static function color(string $text, string $code): string
    {
        return self::isTty() ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    /**
     * @param resource $stream
     */
    private static function write($stream, string $msg): void
    {
        fwrite($stream, $msg . "\n");
    }
}
