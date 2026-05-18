<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Storage\JsonCollectionStorage;
use Cloude\Testing\TestCase;

final class JsonCollectionStorageTest extends TestCase
{
    private string $file;
    private JsonCollectionStorage $storage;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/cloude-json-collection-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);
        $this->file = $dir . '/parties.json';
        $this->storage = new JsonCollectionStorage($this->file, primaryKey: 'slug');

        file_put_contents($this->file, json_encode([
            'psoe' => ['name' => 'Partido Socialista', 'family' => 'left',  'founded' => 1879],
            'pp'   => ['name' => 'Partido Popular',    'family' => 'right', 'founded' => 1989],
            'vox'  => ['name' => 'Vox',                'family' => 'right', 'founded' => 2013],
        ], JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(dirname($this->file));
    }

    public function testFindByPrimaryKeyInjectsTheKey(): void
    {
        $row = $this->storage->find('psoe');
        self::assertNotNull($row);
        self::assertSame('Partido Socialista', $row['name']);
        self::assertSame('psoe', $row['slug'], 'PK should be injected so Model::id() works');
    }

    public function testFindReturnsNullForMissing(): void
    {
        self::assertNull($this->storage->find('nope'));
    }

    public function testFindByCriteria(): void
    {
        $rows = $this->storage->findBy(['family' => 'right'], orderBy: ['founded' => 'ASC']);
        self::assertCount(2, $rows);
        self::assertSame(['pp', 'vox'], array_column($rows, 'slug'));
    }

    public function testCount(): void
    {
        self::assertSame(3, $this->storage->count());
        self::assertSame(2, $this->storage->count(['family' => 'right']));
    }

    public function testInsertAddsEntryAndStripsPkFromValue(): void
    {
        $id = $this->storage->insert(['slug' => 'sumar', 'name' => 'Sumar', 'family' => 'left']);
        self::assertSame('sumar', $id);

        // Read raw file to verify PK is NOT duplicated inside the value
        $raw = json_decode(file_get_contents($this->file), true);
        self::assertArrayHasKey('sumar', $raw);
        self::assertArrayNotHasKey('slug', $raw['sumar'], 'PK should not be duplicated inside the value');
        self::assertSame('Sumar', $raw['sumar']['name']);
    }

    public function testInsertWithoutPkThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->insert(['name' => 'No-slug']);
    }

    public function testUpdateMergesFields(): void
    {
        self::assertTrue($this->storage->update('psoe', ['family' => 'centre-left']));
        $row = $this->storage->find('psoe');
        self::assertSame('centre-left', $row['family']);
        self::assertSame('Partido Socialista', $row['name'], 'unrelated fields survive');
    }

    public function testUpdateMissingReturnsFalse(): void
    {
        self::assertFalse($this->storage->update('not-there', ['name' => 'X']));
    }

    public function testDeleteRemoves(): void
    {
        self::assertTrue($this->storage->delete('vox'));
        self::assertNull($this->storage->find('vox'));
        self::assertSame(2, $this->storage->count());
    }

    public function testDeleteMissingReturnsFalse(): void
    {
        self::assertFalse($this->storage->delete('nope'));
    }

    public function testWorksWithScalarValuesByWrapping(): void
    {
        // Fall-back for translation-style files: {"key": "string", ...}
        file_put_contents($this->file, json_encode([
            'common.save'   => 'Guardar',
            'common.cancel' => 'Cancelar',
        ]));
        $storage = new JsonCollectionStorage($this->file, primaryKey: 'key');

        $row = $storage->find('common.save');
        self::assertSame(['value' => 'Guardar', 'key' => 'common.save'], $row);
    }

    public function testFileMayNotExistYet(): void
    {
        // A fresh storage pointing at a non-existent file: find returns
        // null, count is 0, insert creates it.
        @unlink($this->file);
        self::assertNull($this->storage->find('psoe'));
        self::assertSame(0, $this->storage->count());

        $this->storage->insert(['slug' => 'new', 'name' => 'New']);
        self::assertFileExists($this->file);
        self::assertSame(1, $this->storage->count());
    }
}
