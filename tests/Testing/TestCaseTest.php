<?php

declare(strict_types=1);

namespace Cloude\Tests\Testing;

use Cloude\DateTime;
use Cloude\Http\HttpException;
use Cloude\Http\NotFoundException;
use Cloude\Http\Response;
use Cloude\Model\Model;
use Cloude\Testing\TestCase;

final class ToyUser extends Model
{
    protected static string $table = 'toy_users';
    protected static array $properties = ['id', 'name', 'email', 'active'];
}

/**
 * Self-test: the framework's TestCase is itself written against itself.
 */
final class TestCaseTest extends TestCase
{
    public function testCaptureHttpReturnsBodyAndStatus(): void
    {
        $r = $this->captureHttp(static function (): void {
            Response::json(['ok' => true]);
        });
        self::assertSame(200, $r['status']);
        self::assertSame('{"ok":true}', $r['body']);
    }

    public function testAssertJsonResponseMatchesStructurally(): void
    {
        $this->assertJsonResponse(
            ['users' => ['Ada', 'Grace']],
            static fn () => Response::json(['users' => ['Ada', 'Grace']]),
        );
    }

    public function testAssertJsonResponseWithCustomStatus(): void
    {
        $this->assertJsonResponse(
            ['error' => 'bad'],
            static fn () => Response::json(['error' => 'bad'], 422),
            status: 422,
        );
    }

    public function testAssertHttpExceptionCatchesAndChecksStatus(): void
    {
        $e = $this->assertHttpException(404, static function (): void {
            throw new NotFoundException('book 42');
        });
        self::assertInstanceOf(NotFoundException::class, $e);
        self::assertStringContainsString('book 42', $e->getMessage());

        $this->assertHttpException(403, static function (): void {
            throw new HttpException(403, 'forbidden');
        });
    }

    public function testUseArrayModelConfiguresAndSeeds(): void
    {
        $storage = $this->useArrayModel(ToyUser::class, [
            ['id' => 1, 'name' => 'Ada',   'email' => 'a@x', 'active' => 1],
            ['id' => 2, 'name' => 'Linus', 'email' => 'l@x', 'active' => 0],
        ]);

        $ada = ToyUser::find(1);
        self::assertNotNull($ada);
        self::assertSame('Ada', $ada->name);

        // Round-trip a write through the configured storage.
        ToyUser::create(['id' => 3, 'name' => 'Grace', 'email' => 'g@x', 'active' => 1]);
        self::assertSame(3, ToyUser::count());
        self::assertNotNull($storage->find(3));
    }

    public function testUseSqliteModelCreatesTableAndWiresStorage(): void
    {
        $this->useSqliteModel(ToyUser::class, '
            CREATE TABLE toy_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT,
                email  TEXT,
                active INTEGER DEFAULT 1
            )
        ');
        ToyUser::create(['name' => 'Ada', 'email' => 'a@x', 'active' => 1]);
        $row = ToyUser::find(1);
        self::assertSame('Ada', $row->name);
    }

    public function testFreezeTimePinsDateTimeNow(): void
    {
        $when = $this->freezeTime('2026-05-18 12:00:00');
        self::assertSame('2026-05-18 12:00:00', $when->toDateTimeString());
        self::assertSame('2026-05-18 12:00:00', DateTime::now()->toDateTimeString());

        // Doesn't drift between calls.
        usleep(1000);
        self::assertSame('2026-05-18 12:00:00', DateTime::now()->toDateTimeString());

        $this->unfreezeTime();
        self::assertFalse(DateTime::hasTestNow());
    }

    public function testFreezeTimeReleasedInTearDown(): void
    {
        // setUp() always clears the freeze, so a previous test that
        // forgot to call unfreezeTime() can't poison this one.
        self::assertFalse(DateTime::hasTestNow());
    }

    public function testAssertModelHasChecksAttributeSubset(): void
    {
        $this->useArrayModel(ToyUser::class, [
            ['id' => 1, 'name' => 'Ada', 'email' => 'a@x', 'active' => 1],
        ]);
        $u = ToyUser::find(1);
        $this->assertModelHas($u, ['name' => 'Ada', 'active' => 1]);
    }
}
