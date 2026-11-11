<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Model\Model;
use Cloude\Storage\Query;
use Cloude\Testing\TestCase;

final class UpsertTarget extends Model
{
    protected static string $table = 'targets';
    /** @var list<string> */
    protected static array $properties = ['id', 'plan_id', 'week_no', 'value', 'note', 'tags', 'created_at', 'updated_at'];
    /** @var array<string,string> */
    protected static array $types = ['id' => 'int', 'plan_id' => 'int', 'week_no' => 'int', 'value' => 'int', 'tags' => 'json'];

    /** @var list<string> */
    public static array $afterSaveCalls = [];

    protected function beforeSave(): void
    {
        if (!$this->isPersisted()) {
            $this->created_at ??= 'created';
        }
        $this->updated_at = 'stamp-' . (string) $this->value;
    }

    protected function afterSave(): void
    {
        self::$afterSaveCalls[] = (string) $this->id;
    }
}

/**
 * Native upsert on SQLite (same `ON CONFLICT` form PostgreSQL gets).
 * The MySQL and PostgreSQL servers are covered by the integration
 * tests next to this file.
 */
final class UpsertTest extends TestCase
{
    private const DDL = 'CREATE TABLE targets (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id    INTEGER NOT NULL,
        week_no    INTEGER NOT NULL,
        value      INTEGER,
        note       TEXT,
        tags       TEXT,
        created_at TEXT,
        updated_at TEXT,
        UNIQUE (plan_id, week_no)
    )';

    private \PDO $pdo;

    protected function setUp(): void
    {
        UpsertTarget::$afterSaveCalls = [];
        $this->pdo = $this->useSqliteModel(UpsertTarget::class, self::DDL);
    }

    private function q(): Query
    {
        return new Query($this->pdo, 'targets');
    }

    public function testInsertsThenUpdatesTheSameRow(): void
    {
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5, 'note' => 'first'], ['plan_id', 'week_no']);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 9, 'note' => 'second'], ['plan_id', 'week_no']);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 3, 'value' => 1], ['plan_id', 'week_no']);

        // (No assertion on the second row's id: a collision uses up an
        // auto-increment value on every server.)
        $rows = $this->q()->select('week_no', 'value', 'note')->orderBy('week_no')->get();
        self::assertSame([
            ['week_no' => 2, 'value' => 9, 'note' => 'second'],
            ['week_no' => 3, 'value' => 1, 'note' => null],
        ], $rows);
        self::assertSame(1, $this->q()->where('week_no', 2)->value('id'), 'the updated row keeps its id');
    }

    public function testUpdateListLimitsWhatACollisionOverwrites(): void
    {
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5, 'note' => 'keep'], ['plan_id', 'week_no']);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 9, 'note' => 'ignored'], ['plan_id', 'week_no'], ['value']);

        self::assertSame(['value' => 9, 'note' => 'keep'], $this->q()->select('value', 'note')->first());
    }

    public function testEmptyUpdateListLeavesAnExistingRowAlone(): void
    {
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5], ['plan_id', 'week_no'], []);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 9], ['plan_id', 'week_no'], []);

        self::assertSame(5, $this->q()->value('value'));
        self::assertSame(1, $this->q()->count());
    }

    public function testRejectsAConflictColumnThatIsNotInTheRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("conflict column 'week_no'");
        $this->q()->upsert(['plan_id' => 1, 'value' => 5], ['plan_id', 'week_no']);
    }

    public function testRejectsAnEmptyConflictList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q()->upsert(['plan_id' => 1], []);
    }

    public function testModelUpsertInsertsThenUpdatesAndReturnsTheRow(): void
    {
        $a = UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 5, 'tags' => ['x']]);
        self::assertSame(1, $a->id);
        self::assertTrue($a->isPersisted());
        self::assertFalse($a->isDirty());
        self::assertSame(['x'], $a->tags);
        self::assertSame('created', $a->created_at);
        self::assertSame('stamp-5', $a->updated_at);

        $b = UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 9]);
        self::assertSame(1, $b->id, 'same row, not a second one');
        self::assertSame(9, $b->value);
        self::assertSame(['x'], $b->tags, 'columns outside $values are left alone');
        self::assertSame('created', $b->created_at);
        self::assertSame('stamp-5', $b->updated_at, 'a hook column is only overwritten when listed in $update');
        self::assertSame(1, UpsertTarget::count());
        self::assertSame(['1', '1'], UpsertTarget::$afterSaveCalls);
    }

    public function testModelUpsertUpdateListCanIncludeHookColumns(): void
    {
        UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 5]);
        $b = UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 9], update: ['value', 'updated_at']);

        self::assertSame('stamp-9', $b->updated_at);
        self::assertSame('created', $b->created_at);
    }

    public function testModelUpsertFallsBackOnStoragesWithoutOne(): void
    {
        $store = $this->useMockModel(UpsertTarget::class);

        $a = UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 5, 'note' => 'n']);
        $b = UpsertTarget::upsert(['plan_id' => 1, 'week_no' => 2], ['value' => 9, 'note' => 'changed'], update: ['value']);

        self::assertSame($a->id, $b->id);
        self::assertSame(9, $b->value);
        self::assertSame('n', $b->note);
        $this->assertModelReceived($store, 'insert', times: 1);
        $this->assertModelReceived($store, 'update', times: 1);
    }

    public function testModelUpsertNeedsMatchColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpsertTarget::upsert([], ['value' => 1]);
    }
}
