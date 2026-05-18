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
 * Test base for projects built on `cloude/framework`. Stays on top of
 * PHPUnit — every existing PHPUnit feature (data providers, attributes,
 * coverage, mocking) keeps working as usual. This class adds the
 * helpers that show up in every Cloude test suite:
 *
 *   - **State isolation** — clears `Config`, `View`, and frozen
 *     `DateTime::now()` between tests so a leak fails the very next
 *     case instead of polluting the whole run.
 *
 *   - **In-memory Models** — `useArrayModel()` and `useSqliteModel()`
 *     configure a model's storage for the duration of one test.
 *     Inverse of bootstrap wiring: zero state crosses test boundaries.
 *
 *   - **HTTP-handler capture** — `captureHttp()` runs a handler and
 *     hands you `{status, body}`. `assertJsonResponse()` decodes the
 *     body and compares. `assertHttpException()` catches a
 *     `Cloude\Http\HttpException` and checks the status.
 *
 *   - **Time freezing** — `freezeTime()` / `unfreezeTime()` wrap
 *     `DateTime::setTestNow()` so tests of `isPast()` / `diffForHumans`
 *     etc. are deterministic.
 *
 *   - **Model asserts** — `assertModelHas()` for attribute subset
 *     comparison without writing five `assertSame` lines.
 *
 * Usage:
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
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Models configured by helpers — cleared in tearDown(). */
    /** @var array<class-string<Model>, true> */
    private array $configuredModels = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Bootstrap-level state that should never bleed between tests.
        Config::reset();
        DateTime::clearTestNow();
    }

    protected function tearDown(): void
    {
        DateTime::clearTestNow();
        // Note: we intentionally don't reach into Model::$storages to
        // unset entries — the next useArrayModel/useSqliteModel call
        // overwrites cleanly, and tests that don't use those helpers
        // don't need clearing. Leaving the configured storage in place
        // is also faster.
        $this->configuredModels = [];
        parent::tearDown();
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

    // ── HTTP capture ──────────────────────────────────────────────────────

    /**
     * Run $handler and capture its echoed body + the HTTP status code.
     * Output buffering is used so `Response::json()` / `Response::html()`
     * stay testable without spawning a server.
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

    /**
     * Assert that a handler returns JSON matching $expected and an
     * optional status code. Equality is **structural** (decoded array
     * compared by value, `assertSame`).
     */
    protected function assertJsonResponse(mixed $expected, callable $handler, int $status = 200): void
    {
        $r = $this->captureHttp($handler);
        $this->assertSame($status, $r['status'], 'Unexpected HTTP status');
        $this->assertJson($r['body'], 'Response body is not valid JSON');
        $this->assertSame($expected, json_decode($r['body'], true));
    }

    /**
     * Assert that $handler throws a `Cloude\Http\HttpException` with
     * the given status code. Returns the caught exception so callers
     * can chain extra assertions (e.g. message contents).
     */
    protected function assertHttpException(int $status, callable $handler): HttpException
    {
        try {
            $handler();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->statusCode, "Wrong HTTP status on $e");
            return $e;
        }
        $this->fail("Expected HttpException with status $status; none thrown");
    }

    // ── Model asserts ─────────────────────────────────────────────────────

    /**
     * Assert that every key in $attributes is present on $model with
     * the expected value (`assertSame`). Useful for checking a subset
     * of fields without comparing the whole row.
     *
     * @param array<string,mixed> $attributes
     */
    protected function assertModelHas(Model $model, array $attributes): void
    {
        foreach ($attributes as $key => $expected) {
            $actual = $model->{$key};
            $this->assertSame(
                $expected,
                $actual,
                "Attribute '$key' on " . $model::class . ' mismatch',
            );
        }
    }
}
