<?php

declare(strict_types=1);

namespace Cloude\Tests\Testing;

use Cloude\Model\Model;
use Cloude\Testing\MockStorage;
use Cloude\Testing\TestCase;

final class MockUser extends Model
{
    protected static string $table = 'mock_users';
    protected static array $properties = ['id', 'email', 'active'];
}

final class MockStorageTest extends TestCase
{
    public function testWrapsArrayStorageBehaviour(): void
    {
        $s = new MockStorage([
            ['id' => 1, 'email' => 'ada@x',  'active' => 1],
            ['id' => 2, 'email' => 'alan@x', 'active' => 1],
        ]);

        self::assertSame(['id' => 1, 'email' => 'ada@x', 'active' => 1], $s->find(1));
        self::assertNull($s->find(99));
        self::assertSame(2, $s->count());
        self::assertCount(2, $s->findBy(['active' => 1]));
    }

    public function testRecordsEachCall(): void
    {
        $s = new MockStorage();
        $s->find(7);
        $s->findBy(['active' => 1], limit: 10);
        $s->insert(['email' => 'new@x']);

        self::assertCount(3, $s->calls);
        self::assertSame('find', $s->calls[0]['method']);
        self::assertSame([7], $s->calls[0]['args']);
        self::assertSame('findBy', $s->calls[1]['method']);
        self::assertSame('insert', $s->calls[2]['method']);
    }

    public function testReceivedAndCallsToCounting(): void
    {
        $s = new MockStorage();
        $s->find(1);
        $s->find(2);
        $s->insert(['x' => 1]);

        self::assertTrue($s->received('find'));
        self::assertTrue($s->received('find', times: 2));
        self::assertFalse($s->received('find', times: 1));
        self::assertFalse($s->received('delete'));
        self::assertSame(2, $s->callsTo('find'));
        self::assertSame(0, $s->callsTo('delete'));
    }

    public function testLastCallReturnsMostRecentArgs(): void
    {
        $s = new MockStorage();
        $s->update(1, ['active' => 0]);
        $s->update(2, ['active' => 1]);

        self::assertSame([2, ['active' => 1]], $s->lastCall('update'));
        self::assertNull($s->lastCall('delete'));
    }

    public function testResetCallsKeepsRowsButClearsLog(): void
    {
        $s = new MockStorage([['id' => 1, 'email' => 'ada@x']]);
        $s->find(1);
        self::assertCount(1, $s->calls);

        $s->resetCalls();
        self::assertCount(0, $s->calls);
        self::assertNotNull($s->find(1));   // row is still there
    }

    // ── TestCase integration ─────────────────────────────────────────────

    public function testUseMockModelConfiguresAndReturnsStorage(): void
    {
        $store = $this->useMockModel(MockUser::class, [
            ['id' => 1, 'email' => 'ada@x', 'active' => 1],
        ]);

        $u = MockUser::find(1);
        self::assertSame('ada@x', $u->email);

        $this->assertModelReceived($store, 'find');
        $this->assertModelReceived($store, 'find', times: 1);
    }

    public function testAssertModelReceivedAcceptsCount(): void
    {
        $store = $this->useMockModel(MockUser::class);
        MockUser::count();
        MockUser::count(['active' => 1]);

        $this->assertModelReceived($store, 'count', times: 2);
    }

    public function testAssertModelDidNotReceive(): void
    {
        $store = $this->useMockModel(MockUser::class, [
            ['id' => 1, 'email' => 'ada@x', 'active' => 1],
        ]);
        // Only read, never delete.
        MockUser::find(1);
        $this->assertModelDidNotReceive($store, 'delete');
        $this->assertModelDidNotReceive($store, 'update');
    }

    public function testRecordsModelSaveAsInsertOrUpdate(): void
    {
        $store = $this->useMockModel(MockUser::class);

        // create() → insert()
        $u = MockUser::create(['id' => 7, 'email' => 'ada@x', 'active' => 1]);
        $this->assertModelReceived($store, 'insert', times: 1);

        // mutating an attribute + save() → update()
        $u->active = 0;
        $u->save();
        $this->assertModelReceived($store, 'update', times: 1);
        // Update payload no longer carries the PK (Model strips it).
        $args = $store->lastCall('update');
        self::assertSame(7, $args[0]);
        self::assertSame(['email' => 'ada@x', 'active' => 0], $args[1]);
    }

    public function testRecordsModelDeleteCallsThroughStorage(): void
    {
        $store = $this->useMockModel(MockUser::class, [
            ['id' => 1, 'email' => 'ada@x', 'active' => 1],
        ]);
        $u = MockUser::find(1);
        $u->delete();

        $this->assertModelReceived($store, 'delete', times: 1);
        self::assertSame([1], $store->lastCall('delete'));
    }
}
