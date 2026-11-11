<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\DateTime;
use Cloude\Model\Model;
use Cloude\Testing\TestCase;

final class DirtyPlain extends Model
{
    protected static string $table = 'dirty_plain';
    /** @var list<string> */
    protected static array $properties = ['id', 'email', 'name', 'active'];
}

final class DirtyTyped extends Model
{
    protected static string $table = 'dirty_typed';
    /** @var list<string> */
    protected static array $properties = ['id', 'name', 'age', 'tags', 'born_at', 'updated_at'];
    /** @var array<string,string> */
    protected static array $types = [
        'id'      => 'int',
        'age'     => 'int',
        'tags'    => 'json',
        'born_at' => 'datetime',
    ];

    public static bool $stamp = false;

    protected function beforeSave(): void
    {
        if (self::$stamp) {
            $this->updated_at = 'stamped';
        }
    }
}

/**
 * `save()` on a loaded model writes only the columns whose value
 * differs from what was read (or last saved).
 */
final class ModelDirtyTest extends TestCase
{
    protected function tearDown(): void
    {
        DirtyTyped::$stamp = false;
    }

    public function testUpdateWritesOnlyChangedColumns(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x', 'name' => 'Ada', 'active' => 1],
        ]);

        $u = DirtyPlain::find(1);
        $u->name = 'Ada Lovelace';
        $u->save();

        self::assertSame([1, ['name' => 'Ada Lovelace']], $store->lastCall('update'));
        self::assertSame('ada@x', DirtyPlain::find(1)->email);
    }

    public function testSaveWithNoChangesDoesNotTouchStorage(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x', 'name' => 'Ada', 'active' => 1],
        ]);

        $u = DirtyPlain::find(1);
        self::assertFalse($u->isDirty());
        $u->name = 'Ada';                       // same value
        $u->save();

        $this->assertModelDidNotReceive($store, 'update');
    }

    public function testSecondSaveWritesOnlyWhatChangedSinceTheFirst(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x', 'name' => 'Ada', 'active' => 1],
        ]);

        $u = DirtyPlain::find(1);
        $u->name = 'A';
        $u->save();
        $u->active = 0;
        $u->save();
        $u->save();

        $this->assertModelReceived($store, 'update', times: 2);
        self::assertSame([1, ['active' => 0]], $store->lastCall('update'));
    }

    public function testCreateThenSaveTracksFromTheInsert(): void
    {
        $store = $this->useMockModel(DirtyPlain::class);

        $u = DirtyPlain::create(['email' => 'g@x', 'name' => 'Grace', 'active' => 1]);
        self::assertFalse($u->isDirty());
        $u->save();
        $this->assertModelDidNotReceive($store, 'update');

        $u->email = 'grace@x';
        $u->save();
        self::assertSame(['email' => 'grace@x'], $store->lastCall('update')[1]);
    }

    public function testAttributeSetForTheFirstTimeCountsAsChanged(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x'],
        ]);

        $u = DirtyPlain::find(1);
        $u->name = null;                        // absent from the row that was read
        $u->save();

        self::assertSame([1, ['name' => null]], $store->lastCall('update'));
    }

    public function testCastColumnsAreComparedInStorageForm(): void
    {
        // Storage hands back strings; the casts turn them into int /
        // array / DateTime. Reading and saving untouched must not write.
        $store = $this->useMockModel(DirtyTyped::class, [
            ['id' => '1', 'name' => 'Ada', 'age' => '36', 'tags' => '["a","b"]', 'born_at' => '1815-12-10 00:00:00'],
        ]);

        $u = DirtyTyped::find(1);
        self::assertSame(36, $u->age);
        self::assertFalse($u->isDirty());
        $u->age = 36;
        $u->tags = ['a', 'b'];
        $u->born_at = new DateTime('1815-12-10 00:00:00');
        $u->save();
        $this->assertModelDidNotReceive($store, 'update');

        $u->tags = ['a', 'b', 'c'];
        self::assertTrue($u->isDirty());
        self::assertTrue($u->isDirty('tags'));
        self::assertFalse($u->isDirty('age'));
        self::assertSame(['tags' => '["a","b","c"]'], $u->dirty());
        $u->save();

        self::assertSame([1, ['tags' => '["a","b","c"]']], $store->lastCall('update'));
    }

    public function testBeforeSaveHookColumnsAreWritten(): void
    {
        $store = $this->useMockModel(DirtyTyped::class, [
            ['id' => 1, 'name' => 'Ada', 'age' => 36, 'updated_at' => 'old'],
        ]);
        DirtyTyped::$stamp = true;

        DirtyTyped::find(1)->save();

        self::assertSame([1, ['updated_at' => 'stamped']], $store->lastCall('update'));
    }

    public function testNewModelIsDirtyUntilSaved(): void
    {
        $this->useMockModel(DirtyPlain::class);

        $u = new DirtyPlain(['email' => 'n@x']);
        self::assertTrue($u->isDirty());
        self::assertTrue($u->isDirty('email'));
        self::assertFalse($u->isDirty('name'));
        self::assertSame(['email' => 'n@x'], $u->dirty());

        $u->save();
        self::assertFalse($u->isDirty());
        self::assertSame([], $u->dirty());
    }

    public function testRefreshForgetsUnsavedChanges(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x', 'name' => 'Ada', 'active' => 1],
        ]);

        $u = DirtyPlain::find(1);
        $u->name = 'changed';
        $u->refresh();

        self::assertSame('Ada', $u->name);
        self::assertFalse($u->isDirty());
        $u->save();
        $this->assertModelDidNotReceive($store, 'update');
    }

    public function testUpdateOrCreateWritesOnlyTheNewValues(): void
    {
        $store = $this->useMockModel(DirtyPlain::class, [
            ['id' => 1, 'email' => 'ada@x', 'name' => 'Ada', 'active' => 1],
        ]);

        DirtyPlain::updateOrCreate(['email' => 'ada@x'], ['name' => 'Ada', 'active' => 0]);

        self::assertSame([1, ['active' => 0]], $store->lastCall('update'));
    }
}
