<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

final class JsonStorageTest extends TestCase
{
    private string $dir;
    private JsonStorage $storage;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cloude-json-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
        $this->storage = new JsonStorage($this->dir, autoIncrement: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testInsertAutoIncrementInt(): void
    {
        $id = $this->storage->insert(['name' => 'Ada']);
        self::assertSame(1, $id);
        $id2 = $this->storage->insert(['name' => 'Grace']);
        self::assertSame(2, $id2);
    }

    public function testInsertWithUuidByDefault(): void
    {
        $uuidStorage = new JsonStorage($this->dir);
        $id = $uuidStorage->insert(['name' => 'Ada']);
        self::assertIsString($id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testRoundTrip(): void
    {
        $id = $this->storage->insert(['name' => 'Ada', 'active' => 1]);
        $row = $this->storage->find($id);
        self::assertSame('Ada', $row['name']);
        self::assertSame(1, $row['active']);
    }

    public function testFindByCriteria(): void
    {
        $this->storage->insert(['name' => 'Ada',  'active' => 1]);
        $this->storage->insert(['name' => 'Cain', 'active' => 0]);
        $rows = $this->storage->findBy(['active' => 1]);
        self::assertCount(1, $rows);
        self::assertSame('Ada', $rows[0]['name']);
    }

    public function testCount(): void
    {
        $this->storage->insert(['name' => 'Ada']);
        $this->storage->insert(['name' => 'Grace']);
        self::assertSame(2, $this->storage->count());
    }

    public function testUpdate(): void
    {
        $id = $this->storage->insert(['name' => 'Ada']);
        self::assertTrue($this->storage->update($id, ['name' => 'Ada Lovelace']));
        self::assertSame('Ada Lovelace', $this->storage->find($id)['name']);
    }

    public function testDelete(): void
    {
        $id = $this->storage->insert(['name' => 'Ada']);
        self::assertTrue($this->storage->delete($id));
        self::assertNull($this->storage->find($id));
    }
}
