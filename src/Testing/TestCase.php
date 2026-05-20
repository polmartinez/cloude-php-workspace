<?php

declare(strict_types=1);

namespace Cloude\Testing;

use Cloude\Config;
use Cloude\DateTime;
use Cloude\Http\HttpException;
use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\PdoStorage;

/**
 * Standalone test base for `cloude/framework`. **Does not extend PHPUnit.**
 *
 * Subclasses define `test*` methods (and optional `setUp()` /
 * `tearDown()` hooks). The {@see Runner} discovers them, instantiates
 * the class, runs the lifecycle, and reports pass/fail.
 *
 * Assertions are exposed both as instance methods (`$this->assertSame`)
 * and as static methods (`self::assertSame`) so the PHPUnit muscle
 * memory keeps working. All of them route to {@see Assert}.
 *
 * Framework-specific helpers (state isolation between tests, in-memory
 * Model wiring, HTTP capture, time freezing, MockStorage shortcuts) live
 * alongside the assertion surface.
 *
 *   use Cloude\Testing\TestCase;
 *
 *   final class BookTest extends TestCase
 *   {
 *       public function test_cannot_borrow_when_no_copies(): void
 *       {
 *           $this->useArrayModel(Book::class, [['isbn' => 'X', 'copies' => 0]]);
 *           $book = Book::find('X');
 *           $this->assertHttpException(409, fn () => $book->borrow($memberId));
 *       }
 *   }
 *
 * Run it: `vendor/bin/cloude-test` (or `composer test`).
 */
abstract class TestCase
{
    /** Models configured by helpers — implicitly cleared on next setUp(). */
    /** @var array<class-string<Model>, true> */
    private array $configuredModels = [];

    /**
     * Set by `expectException()`; consumed by the Runner once the test
     * method returns to verify the expectation was met.
     */
    protected ?string $expectedException = null;

    /** Optional substring expected on the thrown exception's message. */
    protected ?string $expectedExceptionMessage = null;

    /** Lifecycle hooks — override in subclasses. */
    protected function setUp(): void {}
    protected function tearDown(): void {}

    /**
     * Called by the Runner before every test. Drops bootstrap-level
     * state that should never bleed between tests, then dispatches to
     * the user's `setUp()`.
     *
     * @internal
     */
    public function runSetUp(): void
    {
        Config::reset();
        DateTime::clearTestNow();
        $this->expectedException = null;
        $this->expectedExceptionMessage = null;
        $this->setUp();
    }

    /**
     * Called by the Runner after every test. Releases time freezes,
     * clears the configured-models registry, then dispatches to user
     * `tearDown()`.
     *
     * @internal
     */
    public function runTearDown(): void
    {
        DateTime::clearTestNow();
        $this->configuredModels = [];
        $this->tearDown();
    }

    /** @internal Read by Runner after a test method runs. */
    public function getExpectedException(): ?string
    {
        return $this->expectedException;
    }

    /** @internal */
    public function getExpectedExceptionMessage(): ?string
    {
        return $this->expectedExceptionMessage;
    }

    // ── exception expectations (PHPUnit-style) ────────────────────────────

    /**
     * Declare that the test is expected to throw an exception of the
     * given class (or one of its descendants). The Runner verifies it
     * was actually thrown.
     *
     * @param class-string<\Throwable> $class
     */
    public function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    /**
     * Combine with {@see expectException()} — assert that the thrown
     * exception's message contains the given substring.
     */
    public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    // ── time freezing ─────────────────────────────────────────────────────

    /**
     * Freeze `Cloude\DateTime::now()` to a fixed instant for this test.
     * Accepts a `DateTime`, a `\DateTimeInterface`, or a parseable
     * string. Released automatically in tearDown.
     */
    protected function freezeTime(string|\DateTimeInterface $when = 'now'): DateTime
    {
        $dt = $when instanceof \DateTimeInterface
            ? $when
            : new DateTime($when);
        DateTime::setTestNow($dt);
        return DateTime::now();
    }

    protected function unfreezeTime(): void
    {
        DateTime::clearTestNow();
    }

    // ── Model wiring ──────────────────────────────────────────────────────

    /**
     * Configure a Model subclass to use an in-memory `ArrayStorage` for
     * the rest of this test. Returns the storage so you can `find` /
     * inspect rows directly.
     *
     * @template T of Model
     * @param  class-string<T>            $modelClass
     * @param  list<array<string,mixed>>  $rows
     */
    protected function useArrayModel(string $modelClass, array $rows = []): ArrayStorage
    {
        $storage = new ArrayStorage($rows);
        $modelClass::configure($storage);
        $this->configuredModels[$modelClass] = true;
        return $storage;
    }

    /**
     * Configure a Model subclass to use an in-memory SQLite connection
     * via `PdoStorage`. You provide the `CREATE TABLE` SQL; the helper
     * runs it on a fresh `sqlite::memory:` PDO and returns the PDO so
     * you can `exec()` more setup if needed.
     *
     * @param class-string<Model> $modelClass
     */
    protected function useSqliteModel(string $modelClass, string $createTableSql): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($createTableSql);
        $modelClass::configure(new PdoStorage($pdo, $modelClass::table()));
        $this->configuredModels[$modelClass] = true;
        return $pdo;
    }

    /**
     * Configure a Model subclass to use a {@see MockStorage} — an
     * in-memory store that **also records every call** so tests can
     * assert on which storage methods the code under test invoked.
     *
     * @template T of Model
     * @param  class-string<T>            $modelClass
     * @param  list<array<string,mixed>>  $rows
     */
    protected function useMockModel(string $modelClass, array $rows = []): MockStorage
    {
        $storage = new MockStorage($rows);
        $modelClass::configure($storage);
        $this->configuredModels[$modelClass] = true;
        return $storage;
    }

    protected function assertModelReceived(MockStorage $storage, string $method, ?int $times = null): void
    {
        $actual = $storage->callsTo($method);
        if ($times === null) {
            Assert::greaterThan(0, $actual, "Expected MockStorage to receive '$method' at least once");
            return;
        }
        Assert::same($times, $actual, "Expected MockStorage to receive '$method' $times time(s)");
    }

    protected function assertModelDidNotReceive(MockStorage $storage, string $method): void
    {
        Assert::same(0, $storage->callsTo($method), "Expected MockStorage NOT to receive '$method'");
    }

    // ── HTTP capture ──────────────────────────────────────────────────────

    /**
     * Run $handler and capture its echoed body + the HTTP status code.
     *
     * @return array{status:int, body:string}
     */
    protected function captureHttp(callable $handler): array
    {
        ob_start();
        try {
            $handler();
        } finally {
            $body = (string) ob_get_clean();
        }
        return [
            'status' => http_response_code() ?: 200,
            'body'   => $body,
        ];
    }

    protected function assertJsonResponse(mixed $expected, callable $handler, int $status = 200): void
    {
        $r = $this->captureHttp($handler);
        Assert::same($status, $r['status'], 'Unexpected HTTP status');
        Assert::json($r['body'], 'Response body is not valid JSON');
        Assert::same($expected, json_decode($r['body'], true));
    }

    /**
     * Assert $handler throws a `Cloude\Http\HttpException` with the
     * given status code. Returns the caught exception for chaining.
     */
    protected function assertHttpException(int $status, callable $handler): HttpException
    {
        try {
            $handler();
        } catch (HttpException $e) {
            Assert::same($status, $e->statusCode, "Wrong HTTP status on $e");
            return $e;
        }
        Assert::fail("Expected HttpException with status $status; none thrown");
    }

    /**
     * @param array<string,mixed> $attributes
     */
    protected function assertModelHas(Model $model, array $attributes): void
    {
        foreach ($attributes as $key => $expected) {
            Assert::same($expected, $model->{$key}, "Attribute '$key' on " . $model::class . ' mismatch');
        }
    }

    // ── PHPUnit-compatible assertion forwarders ──────────────────────────
    //
    // Routes every $this->assertX(...) / self::assertX(...) call to the
    // matching method on Cloude\Testing\Assert. The signatures mirror
    // PHPUnit so migrating tests from `extends PHPUnit\Framework\TestCase`
    // to `extends Cloude\Testing\TestCase` is mechanical.

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::same($expected, $actual, $message);
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::notSame($expected, $actual, $message);
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::equals($expected, $actual, $message);
    }

    public static function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void
    {
        Assert::equalsWithDelta($expected, $actual, $delta, $message);
    }

    public static function assertTrue(mixed $value, string $message = ''): void
    {
        Assert::true($value, $message);
    }

    public static function assertFalse(mixed $value, string $message = ''): void
    {
        Assert::false($value, $message);
    }

    public static function assertNotFalse(mixed $value, string $message = ''): void
    {
        Assert::notFalse($value, $message);
    }

    public static function assertNull(mixed $value, string $message = ''): void
    {
        Assert::null($value, $message);
    }

    public static function assertNotNull(mixed $value, string $message = ''): void
    {
        Assert::notNull($value, $message);
    }

    public static function assertCount(int $expected, mixed $countable, string $message = ''): void
    {
        Assert::count($expected, $countable, $message);
    }

    public static function assertEmpty(mixed $value, string $message = ''): void
    {
        Assert::empty($value, $message);
    }

    public static function assertNotEmpty(mixed $value, string $message = ''): void
    {
        Assert::notEmpty($value, $message);
    }

    public static function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        Assert::instanceOf($class, $value, $message);
    }

    public static function assertNotInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        Assert::notInstanceOf($class, $value, $message);
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::stringContains($needle, $haystack, $message);
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        Assert::stringNotContains($needle, $haystack, $message);
    }

    public static function assertStringStartsWith(string $prefix, string $value, string $message = ''): void
    {
        Assert::stringStartsWith($prefix, $value, $message);
    }

    public static function assertStringEndsWith(string $suffix, string $value, string $message = ''): void
    {
        Assert::stringEndsWith($suffix, $value, $message);
    }

    public static function assertMatchesRegularExpression(string $pattern, string $value, string $message = ''): void
    {
        Assert::matchesRegex($pattern, $value, $message);
    }

    public static function assertIsString(mixed $value, string $message = ''): void
    {
        Assert::isString($value, $message);
    }

    public static function assertJson(string $value, string $message = ''): void
    {
        Assert::json($value, $message);
    }

    /** @param array<mixed>|iterable<mixed> $haystack */
    public static function assertContains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        Assert::contains($needle, $haystack, $message);
    }

    /** @param array<mixed> $array */
    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        Assert::arrayHasKey($key, $array, $message);
    }

    /** @param array<mixed> $array */
    public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        Assert::arrayNotHasKey($key, $array, $message);
    }

    public static function assertGreaterThan(mixed $threshold, mixed $value, string $message = ''): void
    {
        Assert::greaterThan($threshold, $value, $message);
    }

    public static function assertLessThan(mixed $threshold, mixed $value, string $message = ''): void
    {
        Assert::lessThan($threshold, $value, $message);
    }

    public static function assertLessThanOrEqual(mixed $threshold, mixed $value, string $message = ''): void
    {
        Assert::lessThanOrEqual($threshold, $value, $message);
    }

    public static function assertFileExists(string $path, string $message = ''): void
    {
        Assert::fileExists($path, $message);
    }

    public static function assertDirectoryExists(string $path, string $message = ''): void
    {
        Assert::directoryExists($path, $message);
    }

    public static function fail(string $message = ''): never
    {
        Assert::fail($message);
    }
}
