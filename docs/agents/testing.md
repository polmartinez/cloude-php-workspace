# Testing and PHP versions

> Part of the [AGENTS](../../AGENTS.md) reference. How to write and run
> tests with the built-in runner, how to read its output, and how to
> check code against the PHP versions the framework supports.

## Supported PHP

| | |
|---|---|
| Minimum | **8.3** (`"php": ">=8.3"` in `composer.json`) |
| Verified | 8.3, 8.4, 8.5 — full suite, `error_reporting=-1`, no deprecation notice |

Code you add must run on 8.3 **and** stay notice-free on 8.5:

| Don't use (newer than 8.3) | Don't call (deprecated in 8.5) |
|---|---|
| property hooks, asymmetric visibility (8.4) | `ReflectionProperty::setAccessible()`, `ReflectionMethod::setAccessible()` — no effect since 8.1, delete the call |
| `new Foo()->bar()` without parentheses (8.4) | `curl_close()`, `imagedestroy()`, `finfo_close()`, `xml_parser_free()` — no-ops, delete the call |
| `array_find()`, `array_any()`, `array_all()` (8.4) | `(boolean)`, `(integer)`, `(double)`, `(binary)` casts — write `(bool)`, `(int)`, `(float)`, `(string)` |
| `#[\Deprecated]` (8.4) | the backtick operator — use `shell_exec()` |
| pipe operator `\|>`, `#[\NoDiscard]`, `array_first()` / `array_last()` (8.5) | `PDO::MYSQL_ATTR_*` and other driver constants on `PDO` |

Reading private state in a test needs no unlocking call:

```php
$prop = new \ReflectionProperty($object, 'entries');
$value = $prop->getValue($object);          // works on 8.1+ as is

$method = new \ReflectionMethod(Session::class, 'redisSavePath');
$uri = $method->invoke(null, $config);
```

## Running

```bash
vendor/bin/cloude-test                         # everything under tests/  (same as `composer test`)
vendor/bin/cloude-test tests/Storage           # one directory or file
vendor/bin/cloude-test --filter=Upsert         # regex against 'ClassName::method'
vendor/bin/cloude-test --filter=Cast tests/Model
```

Another PHP version: call that binary on the same script.

```bash
/usr/local/opt/php@8.5/bin/php -d error_reporting=-1 vendor/bin/cloude-test
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/cloude-test
```

Exit code: `0` when nothing failed or errored (skips and deprecations
don't count), `1` otherwise or when no test was found.

## Reading the output

```
.....S..F.E
Time: 1.7 s, Tests: 570, Asserts: 1224, Failures: 1, Errors: 1, Skipped: 15

Deprecations (1):
  - Method ReflectionProperty::setAccessible() is deprecated since 8.5 … at tests/FooTest.php:42
```

| Mark | Meaning | Counts as |
|---|---|---|
| `.` | passed | `Tests` |
| `F` | an assertion failed, or an expected exception was not thrown / was another class | `Failures` |
| `E` | an unexpected exception (also from `setUp()`) | `Errors` |
| `S` | `markTestSkipped()` was called | `Skipped` (not in `Tests`) |

`Skipped:` appears only when something was skipped. `Deprecations (n):`
appears only when PHP raised a deprecation during the run; each
distinct notice is listed once with file and line. They are collected
rather than printed mid-run on purpose — printed output would send
headers and break every later test that starts a session or sets a
response code. **A deprecation never fails the run; fix it anyway**:
the list should be empty on every supported version.

## Writing a test

```php
namespace App\Tests;

use Cloude\Testing\TestCase;
use Cloude\Testing\DataProvider;

final class PriceTest extends TestCase
{
    protected function setUp(): void { /* before each test */ }
    protected function tearDown(): void { /* after each test, also after a skip or failure */ }

    public function testRoundsHalfUp(): void
    {
        self::assertSame('1.01', Price::format(1.005));
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('negative');      // substring
        Price::format(-1);
    }

    #[DataProvider('cases')]
    public function testFormat(float $in, string $out): void
    {
        self::assertSame($out, Price::format($in));
    }

    /** @return array<string, array{float, string}> */
    public static function cases(): array
    {
        return ['zero' => [0.0, '0.00'], 'cents' => [0.5, '0.50']];
    }
}
```

Discovery: files named `*Test.php`, classes extending `TestCase`,
public methods starting with `test`. No config file.

### Helpers on `TestCase`

| Need | Call |
|---|---|
| Model on an in-memory array | `$this->useArrayModel(User::class, $rows)` |
| Model on real SQL (in-memory SQLite) — needed for `User::query()`, `upsert()` | `$pdo = $this->useSqliteModel(User::class, 'CREATE TABLE users (…)')` |
| Model on a recording store | `$store = $this->useMockModel(User::class, $rows)` then `$this->assertModelReceived($store, 'update', times: 1)`, `$this->assertModelDidNotReceive($store, 'delete')`, `$store->lastCall('update')` → `[$id, $changedColumns]` |
| Model attributes | `$this->assertModelHas($model, ['name' => 'Ada'])` |
| Run a handler and inspect what it sent | `$r = $this->captureHttp(fn () => $controller->show())` → `['status' => int, 'body' => string]` (output is buffered, nothing is printed) |
| JSON endpoint | `$this->assertJsonResponse(['ok' => true], fn () => …, status: 200)` |
| Expect a 404 / 403 | `$this->assertHttpException(404, fn () => …)` |
| Fixed clock | `$this->freezeTime('2026-05-18 12:00:00')` (released in `tearDown`) |
| Skip | `$this->markTestSkipped('needs ext-redis')` |

Assertions (all static, PHPUnit names): `assertSame`, `assertNotSame`,
`assertEquals`, `assertEqualsWithDelta`, `assertTrue`, `assertFalse`,
`assertNotFalse`, `assertNull`, `assertNotNull`, `assertCount`,
`assertEmpty`, `assertNotEmpty`, `assertInstanceOf`,
`assertNotInstanceOf`, `assertStringContainsString`,
`assertStringNotContainsString`, `assertStringStartsWith`,
`assertStringEndsWith`, `assertMatchesRegularExpression`,
`assertIsString`, `assertJson`, `assertContains`, `assertArrayHasKey`,
`assertArrayNotHasKey`, `assertGreaterThan`, `assertLessThan`,
`assertLessThanOrEqual`, `assertFileExists`, `assertDirectoryExists`,
`fail`. Nothing else exists — no `assertFileDoesNotExist`, no
`expectExceptionMessageMatches`, no mocks beyond `MockStorage`.

### Tests share one process

Every test runs in the same PHP process, in file order. Consequences:

- **Static state leaks.** Reset what you set: `Config::reset()` (and
  `Config::setEnvironment('dev')`), `Cache::reset()`,
  `Connection::reset($name)`, `Transaction::reset($name)`,
  `Bootstrap::$env = null`, superglobals you changed.
- **Never print.** `echo` / `var_dump` in a test or in the code under
  test sends headers; session and response tests after it fail with
  "headers already sent". Wrap handlers that print in `ob_start()` /
  `ob_end_clean()`, or use `captureHttp()`.
- **Sessions**: destroy in `tearDown()`
  (`if (session_status() === PHP_SESSION_ACTIVE) session_destroy();`).

## Tests that need a server or an extension

Skip instead of failing when the requirement is absent:

```php
protected function setUp(): void
{
    $dsn = getenv('APP_TEST_PGSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('APP_TEST_PGSQL_DSN is not set');
    }
    $this->pdo = new \PDO($dsn);
}

protected function tearDown(): void
{
    if (!isset($this->pdo)) {
        return;                      // tearDown also runs after a skip
    }
    $this->pdo->exec('DROP TABLE IF EXISTS app_it_things');
}
```

The framework's own integration tests follow that pattern:

| File | Enabled by |
|---|---|
| `tests/Storage/PgsqlIntegrationTest.php` | `CLOUDE_TEST_PGSQL_DSN`, `CLOUDE_TEST_PGSQL_USER`, `CLOUDE_TEST_PGSQL_PASS` |
| `tests/Storage/MysqlIntegrationTest.php` | `CLOUDE_TEST_MYSQL_DSN`, `CLOUDE_TEST_MYSQL_USER`, `CLOUDE_TEST_MYSQL_PASS` |

They create and drop only tables prefixed `cloude_it_`. What each
database does differently is in [`databases.md`](databases.md).

## Before calling a change done

1. `vendor/bin/cloude-test` — `OK`, and no `Deprecations` block.
2. `composer cs-check` — no diff (`composer cs-fix` applies it).
3. If the change touches SQL: run with the PostgreSQL / MySQL DSNs set
   when a server is available; say so when it was not.
4. If the change uses anything version-sensitive: run on 8.3 and 8.5.
