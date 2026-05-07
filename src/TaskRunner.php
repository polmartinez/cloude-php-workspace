<?php

declare(strict_types=1);

namespace Cloude;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Tiny CLI task runner. Map `prefix:task` invocations to registered
 * callables or to public-static methods of a class — without the magic
 * (no auto-discovery, no annotations, no DI container).
 *
 * Each task receives a single argument: the parsed `$args` array from
 * `Cloude\Cli::parseArgs($argv)`. Use `Cli::flag()`, `Cli::option()` and
 * `Cli::positional()` to read values from it. The handler may return:
 *   - an `int` exit code, or
 *   - `void`, `null`, `true`  → exit 0
 *   - `false`                 → exit 1
 *
 * Built-in subcommands:
 *   list (or no args)   list every registered task with description
 *   help <task>         show description of a single task
 *
 *   php tasks.php                         # list
 *   php tasks.php users:cleanup --days=30 # invoke
 *   php tasks.php help users:cleanup      # describe
 *
 * Exception handling: any uncaught Throwable from a task is reported via
 * `Cli::error()` and the runner exits 1.
 */
class TaskRunner
{
    /** @var array<string, array{handler: callable, description: string}> */
    private array $tasks = [];

    /**
     * Registers a single task. The handler receives the parsed args array
     * and may return int|null|bool|void (see class docblock).
     */
    public function register(string $name, callable $handler, string $description = ''): self
    {
        $this->tasks[$name] = ['handler' => $handler, 'description' => $description];
        return $this;
    }

    /**
     * Registers every public-static method of $class as a task named
     * "$prefix:$kebab", where `$kebab = Str::kebab($methodName)`. Inherited
     * methods are skipped so you can compose multiple task classes safely.
     *
     * The first non-blank line of each method's docblock becomes the task
     * description.
     *
     *   class UserTasks {
     *       /​**​ Cleanup users older than $days. *​/
     *       public static function cleanup(array $args): int { ... }
     *   }
     *
     *   $runner->registerClass('users', UserTasks::class);
     *   // → registers "users:cleanup"
     */
    public function registerClass(string $prefix, string $class): self
    {
        $rc = new ReflectionClass($class);
        $methods = $rc->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);
        foreach ($methods as $m) {
            if ($m->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;   // skip inherited
            }
            $name = $prefix . ':' . Str::kebab($m->getName());
            $desc = self::firstDocLine((string) $m->getDocComment());
            $this->register($name, [$class, $m->getName()], $desc);
        }
        return $this;
    }

    /**
     * Runs the task addressed by $argv. Returns the exit code; the typical
     * caller does `exit($runner->run($argv));`.
     *
     * @param array<int,string> $argv
     */
    public function run(array $argv): int
    {
        // Drop the script name; what remains is "<task> [args...]"
        array_shift($argv);
        $taskName = array_shift($argv);

        if ($taskName === null || $taskName === '' || $taskName === 'list') {
            $this->listTasks();
            return 0;
        }
        if ($taskName === 'help') {
            return $this->showHelp(array_shift($argv));
        }
        if (!isset($this->tasks[$taskName])) {
            Cli::error("Unknown task: {$taskName}");
            Cli::out('Run with no arguments (or `list`) to see available tasks.');
            return 1;
        }

        // Cli::parseArgs skips $argv[0] (the script name). Re-add a dummy
        // so the remaining tokens are parsed as flags / positional args.
        $args = Cli::parseArgs(array_merge(['_'], $argv));

        try {
            $result = ($this->tasks[$taskName]['handler'])($args);
        } catch (Throwable $e) {
            Cli::error($e->getMessage());
            return 1;
        }

        if (is_int($result)) {
            return $result;
        }
        return $result === false ? 1 : 0;
    }

    private function listTasks(): void
    {
        if ($this->tasks === []) {
            Cli::out('No tasks registered.');
            return;
        }
        Cli::out('Available tasks:');
        Cli::out('');
        $width = max(array_map('strlen', array_keys($this->tasks)));
        foreach ($this->tasks as $name => $task) {
            $padded = str_pad($name, $width + 2);
            $desc = $task['description'] !== '' ? $task['description'] : '(no description)';
            Cli::out('  ' . $padded . $desc);
        }
    }

    private function showHelp(?string $taskName): int
    {
        if ($taskName === null || $taskName === '') {
            Cli::out('Usage: php <script>.php <task> [--option=value ...] [args...]');
            Cli::out('       php <script>.php list');
            Cli::out('       php <script>.php help <task>');
            Cli::out('');
            $this->listTasks();
            return 0;
        }
        if (!isset($this->tasks[$taskName])) {
            Cli::error("Unknown task: {$taskName}");
            return 1;
        }
        $task = $this->tasks[$taskName];
        Cli::out($taskName);
        Cli::out('  ' . ($task['description'] !== '' ? $task['description'] : '(no description)'));
        return 0;
    }

    /**
     * Extracts the first non-empty, non-tag line from a docblock.
     */
    private static function firstDocLine(string $doc): string
    {
        if ($doc === '') {
            return '';
        }
        $lines = preg_split('/\R/', $doc) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = (string) preg_replace('#^/\*\*\s*|\s*\*/$|^\*\s*#', '', $line);
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            return $line;
        }
        return '';
    }
}
