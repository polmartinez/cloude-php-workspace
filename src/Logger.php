<?php

declare(strict_types=1);

namespace Cloude;

/**
 * File-backed logger with optional daily rotation.
 *
 * Designed to be small and obvious, not to compete with Monolog. Writes one
 * line per call:
 *
 *   [2026-05-07T08:30:12Z] [INFO] processed 42 items {"flow":"backfill"}
 *
 * Levels (in order): debug → info → warn → error. Messages below `minLevel`
 * are dropped. Context arrays are appended as compact JSON when non-empty.
 *
 * Rotation modes:
 *   - 'daily' (default): "/var/log/app.log" → "/var/log/app-2026-05-07.log".
 *   - 'none': always write to the configured path.
 *
 * Usage:
 *
 *   $log = new Logger('/var/log/app.log', minLevel: 'info');
 *   $log->info('http request', ['path' => '/foo']);
 *   $log->error('db unreachable');
 */
class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];

    private string $path;
    private int $minLevel;
    private string $rotation;

    public function __construct(string $path, string $minLevel = 'info', string $rotation = 'daily')
    {
        $this->path = $path;
        $this->minLevel = self::levelOrder($minLevel);
        if (!in_array($rotation, ['daily', 'none'], true)) {
            throw new \InvalidArgumentException("Unknown rotation mode '$rotation' (use 'daily' or 'none')");
        }
        $this->rotation = $rotation;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warn(string $message, array $context = []): void
    {
        $this->log('warn', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (self::levelOrder($level) < $this->minLevel) {
            return;
        }
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . Format::jsonEncode($context),
        );
        $target = $this->resolvePath();
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($target, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolvePath(): string
    {
        if ($this->rotation === 'none') {
            return $this->path;
        }
        $info = pathinfo($this->path);
        $dir = $info['dirname'] ?? '.';
        $name = $info['filename'] ?? 'app';
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        return $dir . '/' . $name . '-' . gmdate('Y-m-d') . $ext;
    }

    private static function levelOrder(string $level): int
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("Unknown log level '$level'");
        }
        return self::LEVELS[$level];
    }
}
