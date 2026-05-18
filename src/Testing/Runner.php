<?php

declare(strict_types=1);

namespace Cloude\Testing;

/**
 * Discovery + execution + reporting for `Cloude\Testing\TestCase`
 * subclasses. Designed to be small enough that you can read it in one
 * sitting — no parallelism, no process isolation, no XML output, no
 * fixture caching. Just enough to run a project's tests with a clear
 * pass/fail summary.
 *
 * Discovery:
 *   - Each `--path` argument is walked recursively
 *   - Files matching `*Test.php` are required
 *   - After require, every newly declared class that extends TestCase
 *     becomes a test class
 *   - Public methods named `test*` (or with `#[Test]` — TODO) become
 *     test methods
 *
 * Execution:
 *   - For each test method, the framework instantiates the class,
 *     runs `runSetUp()`, invokes the method, runs `runTearDown()`
 *   - `AssertionFailedException` → failure
 *   - Any other `\Throwable` → error (still printed with stack trace)
 *   - `#[DataProvider('name')]` → one invocation per row, each labelled
 *     with the row key
 *
 * Reporting:
 *   - One dot per pass, `F` per failure, `E` per error (PHPUnit-style)
 *   - Final summary: counts + listed failures with file:line + message
 *   - Exit code 0 when everything passed; 1 otherwise
 *
 * Use via `bin/cloude-test` or `Runner::main($argv)`.
 */
final class Runner
{
    /** @var list<string> */
    private array $paths = [];
    private ?string $filter = null;

    private int $passed = 0;
    private int $failed = 0;
    private int $errors = 0;

    /** @var list<array{label:string, message:string, trace:string}> */
    private array $failures = [];
    /** @var list<array{label:string, message:string, trace:string}> */
    private array $errorList = [];

    /**
     * Parse argv and run. Returns the exit code (0 success, 1 any
     * failure or error).
     *
     *   vendor/bin/cloude-test [--filter=Pattern] [--] path1 path2 ...
     *
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $runner = new self();
        $paths = [];
        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--filter=')) {
                $runner->filter = substr($arg, strlen('--filter='));
            } elseif ($arg === '--filter') {
                $i++;
                $runner->filter = $argv[$i] ?? null;
            } elseif ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, self::usage());
                return 0;
            } elseif ($arg === '--') {
                // explicit separator; everything after is a path
            } else {
                $paths[] = $arg;
            }
            $i++;
        }
        if ($paths === []) {
            $paths[] = 'tests';
        }
        $runner->paths = $paths;
        return $runner->run();
    }

    private static function usage(): string
    {
        return <<<TXT
        cloude-test — Cloude framework test runner

        Usage:
          cloude-test [--filter=Pattern] [path ...]

        Options:
          --filter=PATTERN    Run only tests whose 'ClassName::method' matches the regex
          -h, --help          Show this help

        Examples:
          cloude-test
          cloude-test tests/Storage
          cloude-test --filter=Cast tests/Model

        TXT;
    }

    public function run(): int
    {
        $start = microtime(true);

        // Discover before any test runs so the dots come out cleanly.
        $tests = $this->discover();

        if ($tests === []) {
            fwrite(STDERR, 'No tests found in: ' . implode(', ', $this->paths) . "\n");
            return 1;
        }

        $this->writeBanner(count($tests));

        Assert::resetCount();

        $perLine = 60;
        $printed = 0;
        foreach ($tests as $t) {
            $this->execute($t);
            $printed++;
            if ($printed % $perLine === 0) {
                fwrite(STDOUT, '  ' . $printed . ' / ' . count($tests) . "\n");
            }
        }
        if ($printed % $perLine !== 0) {
            fwrite(STDOUT, str_repeat(' ', $perLine - ($printed % $perLine))
                . '  ' . $printed . ' / ' . count($tests) . "\n");
        }

        $this->writeSummary(microtime(true) - $start);

        return ($this->failed + $this->errors) === 0 ? 0 : 1;
    }

    /**
     * @return list<array{class:string, method:string, args:array<mixed>, label:string}>
     */
    private function discover(): array
    {
        $declaredBefore = get_declared_classes();
        foreach ($this->paths as $path) {
            $this->walk($path);
        }
        $declared = array_diff(get_declared_classes(), $declaredBefore);

        $tests = [];
        foreach ($declared as $class) {
            if (!is_subclass_of($class, TestCase::class)) {
                continue;
            }
            $rc = new \ReflectionClass($class);
            if ($rc->isAbstract()) {
                continue;
            }
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if (!str_starts_with($method->getName(), 'test')) {
                    continue;
                }
                if ($method->isStatic() || $method->isAbstract()) {
                    continue;
                }
                $providerRows = $this->resolveDataProvider($method, $class);
                if ($providerRows === null) {
                    $label = $class . '::' . $method->getName();
                    if (!$this->matchesFilter($label)) {
                        continue;
                    }
                    $tests[] = [
                        'class'  => $class,
                        'method' => $method->getName(),
                        'args'   => [],
                        'label'  => $label,
                    ];
                    continue;
                }
                foreach ($providerRows as $key => $args) {
                    $label = $class . '::' . $method->getName() . ' [' . $key . ']';
                    if (!$this->matchesFilter($label)) {
                        continue;
                    }
                    $tests[] = [
                        'class'  => $class,
                        'method' => $method->getName(),
                        'args'   => is_array($args) ? array_values($args) : [$args],
                        'label'  => $label,
                    ];
                }
            }
        }
        return $tests;
    }

    /**
     * @return iterable<int|string, mixed>|null  null when the method has no provider
     */
    private function resolveDataProvider(\ReflectionMethod $method, string $class): ?iterable
    {
        $attrs = $method->getAttributes(DataProvider::class);
        // Be liberal: also accept PHPUnit's attribute if the test file
        // still imports it (eases migration). Match by simple name.
        if ($attrs === []) {
            foreach ($method->getAttributes() as $a) {
                if (str_ends_with($a->getName(), '\\DataProvider')) {
                    $args = $a->getArguments();
                    if (!isset($args[0]) || !is_string($args[0])) {
                        continue;
                    }
                    return $this->callProvider($class, $args[0]);
                }
            }
            return null;
        }
        /** @var DataProvider $instance */
        $instance = $attrs[0]->newInstance();
        return $this->callProvider($class, $instance->methodName);
    }

    /**
     * @return iterable<int|string, mixed>
     */
    private function callProvider(string $class, string $method): iterable
    {
        if (!method_exists($class, $method)) {
            throw new \RuntimeException("DataProvider '$class::$method' does not exist");
        }
        /** @var iterable<int|string, mixed> $rows */
        $rows = $class::$method();
        return $rows;
    }

    private function matchesFilter(string $label): bool
    {
        if ($this->filter === null) {
            return true;
        }
        return preg_match('/' . str_replace('/', '\\/', $this->filter) . '/', $label) === 1;
    }

    private function walk(string $path): void
    {
        if (is_file($path)) {
            if (str_ends_with($path, 'Test.php')) {
                require_once $path;
            }
            return;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "Path not found: $path\n");
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                require_once $file->getPathname();
            }
        }
    }

    /**
     * @param array{class:string, method:string, args:array<mixed>, label:string} $test
     */
    private function execute(array $test): void
    {
        $class  = $test['class'];
        $method = $test['method'];
        $label  = $test['label'];
        $args   = $test['args'];

        /** @var TestCase $instance */
        $instance = new $class();
        try {
            $instance->runSetUp();
        } catch (\Throwable $e) {
            $this->recordError($label . ' (setUp)', $e);
            fwrite(STDOUT, 'E');
            return;
        }

        $expectedException = null;
        $expectedMessage   = null;
        $thrown = null;
        try {
            $instance->{$method}(...$args);
            $expectedException = $instance->getExpectedException();
            $expectedMessage   = $instance->getExpectedExceptionMessage();
        } catch (AssertionFailedException $e) {
            $this->failed++;
            $this->failures[] = [
                'label'   => $label,
                'message' => $e->getMessage(),
                'trace'   => $this->shortTrace($e),
            ];
            fwrite(STDOUT, 'F');
            $this->safeTearDown($instance);
            return;
        } catch (\Throwable $e) {
            $thrown = $e;
            $expectedException = $instance->getExpectedException();
            $expectedMessage   = $instance->getExpectedExceptionMessage();
        }

        // If the test declared an expectation, verify it.
        if ($expectedException !== null) {
            if ($thrown === null) {
                $this->failed++;
                $this->failures[] = [
                    'label'   => $label,
                    'message' => "Expected exception '$expectedException' was not thrown",
                    'trace'   => '',
                ];
                fwrite(STDOUT, 'F');
                $this->safeTearDown($instance);
                return;
            }
            if (!($thrown instanceof $expectedException)) {
                $this->failed++;
                $this->failures[] = [
                    'label'   => $label,
                    'message' => "Expected '$expectedException', got '" . $thrown::class . "': " . $thrown->getMessage(),
                    'trace'   => $this->shortTrace($thrown),
                ];
                fwrite(STDOUT, 'F');
                $this->safeTearDown($instance);
                return;
            }
            if ($expectedMessage !== null && !str_contains($thrown->getMessage(), $expectedMessage)) {
                $this->failed++;
                $this->failures[] = [
                    'label'   => $label,
                    'message' => "Exception message '{$thrown->getMessage()}' does not contain '$expectedMessage'",
                    'trace'   => '',
                ];
                fwrite(STDOUT, 'F');
                $this->safeTearDown($instance);
                return;
            }
            // Expectation met.
            $this->passed++;
            fwrite(STDOUT, '.');
            $this->safeTearDown($instance);
            return;
        }

        if ($thrown !== null) {
            $this->recordError($label, $thrown);
            fwrite(STDOUT, 'E');
            $this->safeTearDown($instance);
            return;
        }

        $this->passed++;
        fwrite(STDOUT, '.');
        $this->safeTearDown($instance);
    }

    private function safeTearDown(TestCase $instance): void
    {
        try {
            $instance->runTearDown();
        } catch (\Throwable $e) {
            $this->errors++;
            $this->errorList[] = [
                'label'   => $instance::class . ' (tearDown)',
                'message' => $e->getMessage(),
                'trace'   => $this->shortTrace($e),
            ];
        }
    }

    private function recordError(string $label, \Throwable $e): void
    {
        $this->errors++;
        $this->errorList[] = [
            'label'   => $label,
            'message' => $e::class . ': ' . $e->getMessage(),
            'trace'   => $this->shortTrace($e),
        ];
    }

    private function shortTrace(\Throwable $e): string
    {
        $lines = [];
        $lines[] = '  at ' . $e->getFile() . ':' . $e->getLine();
        $frames = array_slice($e->getTrace(), 0, 8);
        foreach ($frames as $f) {
            $file = $f['file'] ?? '?';
            $line = $f['line'] ?? '?';
            $call = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?');
            $lines[] = "  - {$file}:{$line}  {$call}()";
        }
        return implode("\n", $lines);
    }

    private function writeBanner(int $count): void
    {
        $php = PHP_VERSION;
        $tty = self::isTty();
        $banner = "Cloude Test Runner\n"
            . "PHP $php — discovered $count test(s)\n\n";
        if ($tty) {
            fwrite(STDOUT, "\033[1m" . $banner . "\033[0m");
        } else {
            fwrite(STDOUT, $banner);
        }
    }

    private function writeSummary(float $elapsed): void
    {
        $tty = self::isTty();
        $total = $this->passed + $this->failed + $this->errors;
        $asserts = Assert::assertionCount();

        $out = "\n\nTime: " . sprintf('%.3f s', $elapsed)
            . ', Tests: ' . $total
            . ', Asserts: ' . $asserts
            . ', Failures: ' . $this->failed
            . ', Errors: ' . $this->errors . "\n";

        $ok = ($this->failed + $this->errors) === 0;

        if ($this->failures !== []) {
            $out .= "\nFailures:\n";
            foreach ($this->failures as $i => $f) {
                $n = $i + 1;
                $out .= "\n  $n) {$f['label']}\n";
                $out .= '     ' . str_replace("\n", "\n     ", $f['message']) . "\n";
                if ($f['trace'] !== '') {
                    $out .= '     ' . str_replace("\n", "\n     ", $f['trace']) . "\n";
                }
            }
        }

        if ($this->errorList !== []) {
            $out .= "\nErrors:\n";
            foreach ($this->errorList as $i => $e) {
                $n = $i + 1;
                $out .= "\n  $n) {$e['label']}\n";
                $out .= '     ' . str_replace("\n", "\n     ", $e['message']) . "\n";
                if ($e['trace'] !== '') {
                    $out .= '     ' . str_replace("\n", "\n     ", $e['trace']) . "\n";
                }
            }
        }

        $verdict = $ok ? "\nOK\n" : "\nFAILED\n";
        if ($tty) {
            $verdict = $ok ? "\n\033[32mOK\033[0m\n" : "\n\033[31mFAILED\033[0m\n";
        }

        fwrite(STDOUT, $out . $verdict);
    }

    private static function isTty(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
